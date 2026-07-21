<?php

declare(strict_types=1);

/**
 * CPR P5 Integration Baseline — full PONR production-apply chain (WP-P5-06).
 *
 * Orchestrates existing P4 Pre-PONR + P5 live modules only (no new business logic).
 * Validates DELETE → CP6 → IMPORT 1→6 → CP7 → Special → CP8 → Uploads → CP9.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P5_06_INTEGRATION_BASELINE.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P5_ARTIFACT_INDEX.md §8
 */

require_once __DIR__ . '/cpr_p4_integration.php';
require_once __DIR__ . '/cpr_uploads_live.php';
require_once __DIR__ . '/cpr_p5_control_plane.php';
require_once __DIR__ . '/cpr_import_batches.php';

const ORANGE_CPR_P5_INTEGRATION_SCHEMA = 'cpr_p5_integration/1';
const ORANGE_CPR_P5_INTEGRATION_VERSION = 'P5-06-1.0';

const ORANGE_CPR_P5INT_ERR_ENABLEMENT = 'p5int_enablement_forbidden';
const ORANGE_CPR_P5INT_ERR_ACTOR = 'p5int_actor_not_super_admin';
const ORANGE_CPR_P5INT_ERR_CHAIN = 'p5int_chain_failed';
const ORANGE_CPR_P5INT_ERR_VERIFY = 'p5int_verification_failed';
const ORANGE_CPR_P5INT_ERR_PERSIST = 'p5int_persist_failed';
const ORANGE_CPR_P5INT_ERR_BYPASS = 'p5int_bypass_forbidden';
const ORANGE_CPR_P5INT_ERR_P6 = 'p5int_p6_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_p5int_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
        'p5_baseline_ready' => false,
        'p6_started' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_p5int_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'fail_closed' => true,
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
        'p5_baseline_ready' => false,
        'p6_started' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
    ], $extra);
}

/**
 * Canonical P5 Production Apply stage order (Architecture §6 / Artifact Index §8).
 *
 * @return list<string>
 */
function orange_cpr_p5_integration_stage_order(): array
{
    return [
        'p4_pre_ponr_through_cpa',
        'target_slice_delete',
        'cp6',
        'target_slice_import_batches_1_6',
        'cp7',
        'special_handlers',
        'cp8',
        'country_uploads_apply',
        'cp9',
    ];
}

/**
 * @param array<string, mixed> $record
 */
function orange_cpr_p5_integration_persist(string $cprRoot, string $jobId, string $basename, array $record): string
{
    $dir = orange_cpr_integration_live_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR integration_live directory.');
    }
    $sealed = orange_cpr_auth_seal($record);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $basename) ?? 'record';
    $final = $dir . DIRECTORY_SEPARATOR . $safe . '.json';
    if (is_file($final)) {
        throw new RuntimeException('P5 integration record already exists: ' . $safe);
    }
    $tmp = $final . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('P5 integration record write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $final);

    return $final;
}

function orange_cpr_p5_integration_latest_path(string $cprRoot, string $jobId): string
{
    return orange_cpr_integration_live_directory($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_p5_integration_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_p5_integration_load_latest(string $cprRoot, string $jobId): ?array
{
    $path = orange_cpr_p5_integration_latest_path($cprRoot, $jobId);
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
 * @return array<string, mixed>
 */
function orange_cpr_p5_integration_default_slice(): array
{
    return [
        'country_id' => 1,
        'country_code' => 'KW',
        'delete_order_version' => ORANGE_CPR_DELETE_ORDER_VERSION,
        'tables' => [
            ['table' => 'order_items', 'membership_key' => 'country_id', 'row_ids' => ['11', '12']],
            ['table' => 'orders', 'membership_key' => 'country_id', 'row_ids' => ['1']],
            ['table' => 'product_channels', 'membership_key' => 'country_id', 'row_ids' => ['pc1']],
            ['table' => 'products', 'membership_key' => 'country_id', 'row_ids' => ['p1']],
            ['table' => 'storefront_accounts', 'membership_key' => 'country_id', 'row_ids' => ['sa1']],
        ],
    ];
}

/**
 * @param list<string> $paths
 * @return array<string, mixed>
 */
function orange_cpr_p5_integration_build_upload_manifest(
    string $jobId,
    string $packageFingerprint,
    int $countryId,
    string $countryCode,
    array $paths
): array {
    $normalized = [];
    foreach ($paths as $p) {
        $n = orange_cpr_uploads_live_normalize_path($p);
        if ($n === null) {
            throw new RuntimeException('Invalid upload path in integration fixture: ' . $p);
        }
        $normalized[] = $n;
    }
    sort($normalized, SORT_STRING);
    $canonical = [
        'job_id' => $jobId,
        'country_id' => $countryId,
        'package_fingerprint' => $packageFingerprint,
        'schema_revision' => 121,
        'paths' => $normalized,
    ];
    $sha = hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return [
        'manifest_id' => 'um-p5int-' . substr(hash('sha256', $jobId . $packageFingerprint), 0, 12),
        'job_id' => $jobId,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'package_fingerprint' => $packageFingerprint,
        'schema_revision' => 121,
        'paths' => $normalized,
        'manifest_sha256' => $sha,
        'manifest_sealed' => true,
        'require_non_empty' => true,
    ];
}

/**
 * @param list<string> $paths
 * @return list<array<string, mixed>>
 */
function orange_cpr_p5_integration_upload_artifacts(array $paths, int $countryId): array
{
    $out = [];
    foreach ($paths as $i => $p) {
        $content = 'p5int-blob-' . $p . '-' . (string) $i;
        $out[] = [
            'relative_path' => $p,
            'country_id' => $countryId,
            'content' => $content,
            'sha256' => hash('sha256', $content),
        ];
    }

    return $out;
}

/**
 * Post-chain verification for the full P5 PONR apply path (fail-closed).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_p5_integration_verify(array $env, string $jobId, array $ctx): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $checks = [];
    $fail = static function (string $id, string $msg) use (&$checks): void {
        $checks[] = ['id' => $id, 'ok' => false, 'message' => $msg];
    };
    $pass = static function (string $id, string $msg = 'ok') use (&$checks): void {
        $checks[] = ['id' => $id, 'ok' => true, 'message' => $msg];
    };

    if (orange_cpr_enablement_flag_read($env)) {
        $fail('enablement_false', 'Ops enablement must be FALSE.');
    } else {
        $pass('enablement_false');
    }

    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_p5int_fail(ORANGE_CPR_P5INT_ERR_VERIFY, $e->getMessage());
    }

    if ((string) ($job['state'] ?? '') !== 'cpr_uploads_applying') {
        $fail('state_uploads_applying', 'Expected cpr_uploads_applying; got ' . (string) ($job['state'] ?? ''));
    } else {
        $pass('state_uploads_applying');
    }
    if (empty($job['ponr_crossed'])) {
        $fail('ponr_crossed', 'ponr_crossed must be true after DELETE.');
    } else {
        $pass('ponr_crossed');
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        $fail('contract_frozen', 'Frozen contract required.');
    } else {
        $pass('contract_frozen');
        if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
            || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
        ) {
            $fail('job_identity_continuity', 'Contract/job identity drift.');
        } else {
            $pass('job_identity_continuity');
        }
        if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($ctx['package_fingerprint'] ?? '')
            || (string) ($job['job_id'] ?? $jobId) !== $jobId
        ) {
            $fail('fingerprint_integrity', 'Package fingerprint / job_id continuity broken.');
        } else {
            $pass('fingerprint_integrity');
        }
    }

    $requiredCp = [
        'CP0', 'CP2', 'CP3', 'CP4', 'CP1', 'runbook_pre_ponr', 'CP5', 'CP-A',
        'CP6', 'CP7', 'CP8', 'CP9',
    ];
    foreach ($requiredCp as $cp) {
        if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, $cp)) {
            $fail('checkpoint_' . $cp, 'Missing checkpoint ' . $cp);
        } else {
            $pass('checkpoint_' . $cp);
        }
    }
    if (orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP10')) {
        $fail('no_cp10', 'CP10 must not exist (P6 not started).');
    } else {
        $pass('no_cp10');
    }

    $order = orange_cpr_checkpoint_write_order();
    $lastIdx = -1;
    $orderOk = true;
    foreach ($requiredCp as $cp) {
        $idx = array_search($cp, $order, true);
        if ($idx === false || $idx < $lastIdx) {
            $orderOk = false;
            break;
        }
        $lastIdx = (int) $idx;
    }
    if ($orderOk) {
        $pass('checkpoint_ordering');
    } else {
        $fail('checkpoint_ordering', 'Checkpoint DAG order violated.');
    }

    // No duplicate checkpoint final files for P5 CPs.
    $cpDir = orange_cpr_checkpoints_directory($cprRoot, $jobId);
    $dupOk = true;
    foreach (['CP6', 'CP7', 'CP8', 'CP9'] as $cp) {
        $matches = glob($cpDir . DIRECTORY_SEPARATOR . $cp . '*.json') ?: [];
        $finals = array_values(array_filter($matches, static function (string $p): bool {
            return !str_contains(basename($p), '.tmp.');
        }));
        if (count($finals) !== 1) {
            $dupOk = false;
            $fail('no_duplicate_' . $cp, 'Expected exactly one sealed ' . $cp . ' file.');
        }
    }
    if ($dupOk) {
        $pass('no_duplicate_checkpoints');
    }

    $lock = orange_cpr_lock_read($cprRoot);
    if (!is_array($lock)
        || (string) ($lock['job_id'] ?? '') !== $jobId
        || (string) ($lock['ownership']['lease_token'] ?? '') !== (string) ($ctx['lease_token'] ?? '')
        || (string) ($lock['ownership']['worker_id'] ?? '') !== (string) ($ctx['worker_id'] ?? '')
    ) {
        $fail('lock_ownership', 'Lock ownership drift after P5 chain.');
    } else {
        $pass('lock_ownership');
    }

    $del = orange_cpr_delete_live_load_latest($cprRoot, $jobId, 'report');
    if (!is_array($del) || empty($del['delete_complete']) || !orange_cpr_auth_verify_seal($del)) {
        $fail('delete_report', 'Sealed DELETE report missing.');
    } else {
        $pass('delete_report');
        if (($del['production_sql_executed'] ?? true) !== false) {
            $fail('delete_no_sql', 'DELETE reported production SQL.');
        } else {
            $pass('delete_no_sql');
        }
        if ((int) ($del['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)) {
            $fail('no_cross_country_delete', 'DELETE country mismatch.');
        } else {
            $pass('no_cross_country_delete');
        }
    }

    $imp = orange_cpr_import_live_load_latest($cprRoot, $jobId, 'summary');
    if (!is_array($imp) || empty($imp['import_complete']) || !orange_cpr_auth_verify_seal($imp)) {
        $fail('import_report', 'Sealed IMPORT summary missing.');
    } else {
        $pass('import_report');
        if (($imp['production_sql_executed'] ?? true) !== false) {
            $fail('import_no_sql', 'IMPORT reported production SQL.');
        } else {
            $pass('import_no_sql');
        }
    }

    $batchNums = orange_cpr_import_batch_numbers();
    $batchOrderOk = true;
    foreach ($batchNums as $bn) {
        $br = orange_cpr_import_live_load_batch_report($cprRoot, $jobId, $bn);
        if (!is_array($br) || !orange_cpr_auth_verify_seal($br) || (int) ($br['batch'] ?? 0) !== $bn) {
            $batchOrderOk = false;
            $fail('batch_' . (string) $bn, 'Sealed import batch ' . (string) $bn . ' missing/mismatch.');
            break;
        }
    }
    if ($batchOrderOk && $batchNums === [1, 2, 3, 4, 5, 6]) {
        $pass('batch_ordering_1_6');
    } elseif ($batchOrderOk) {
        $fail('batch_ordering_1_6', 'Unexpected batch number set.');
    }

    $special = orange_cpr_special_live_load_latest($cprRoot, $jobId, 'report');
    if (!is_array($special) || empty($special['special_handlers_complete']) || !orange_cpr_auth_verify_seal($special)) {
        $fail('special_report', 'Sealed Special Handlers report missing.');
    } else {
        $pass('special_report');
        if (($special['production_sql_executed'] ?? true) !== false) {
            $fail('special_no_sql', 'Special handlers reported production SQL.');
        } else {
            $pass('special_no_sql');
        }
    }

    $uploads = orange_cpr_uploads_live_load_latest($cprRoot, $jobId, 'report');
    if (!is_array($uploads) || empty($uploads['uploads_complete']) || !orange_cpr_auth_verify_seal($uploads)) {
        $fail('uploads_report', 'Sealed uploads report missing.');
    } else {
        $pass('uploads_report');
        if (empty($uploads['scoped_only']) || !empty($uploads['full_tree_mode'])) {
            $fail('upload_isolation', 'Uploads must be scoped_only / not full_tree.');
        } else {
            $pass('upload_isolation');
        }
        if (($uploads['production_sql_executed'] ?? true) !== false
            || ($uploads['production_uploads_mutated'] ?? true) !== false
        ) {
            $fail('uploads_no_production_mutation', 'Uploads mutated production or executed SQL.');
        } else {
            $pass('uploads_no_production_mutation');
        }
        if (!is_array($uploads['recovery_metadata'] ?? null)) {
            $fail('recovery_metadata', 'Uploads recovery_metadata missing.');
        } else {
            $pass('recovery_metadata');
        }
    }

    $cp9 = orange_cpr_checkpoint_load($env, $jobId, 'CP9');
    if (!empty($cp9['ok'])) {
        $payload = is_array($cp9['checkpoint']['payload'] ?? null) ? $cp9['checkpoint']['payload'] : [];
        if (!empty($payload['scoped_only'])
            && (string) ($payload['pre_image_manifest_id'] ?? '') !== ''
            && isset($payload['files_applied_count'])
        ) {
            $pass('cp9_payload');
        } else {
            $fail('cp9_payload', 'CP9 payload missing scoped_only / pre_image / files_applied_count.');
        }
    } else {
        $fail('cp9_payload', 'CP9 unreadable.');
    }

    $audit = orange_cpr_p4_integration_read_audit($env, $jobId);
    $auditNeed = [
        'cpr.gates_live_evaluate',
        'cpr.authority_live_authorize',
        'cpr.witnesses_live_commit_cpa',
        'cpr.delete_live_complete',
        'cpr.import_live_complete',
        'cpr.special_live_complete',
        'cpr.uploads_live_complete',
    ];
    // Allow alternate delete/import audit names if engines use variants.
    $seen = [];
    foreach ($audit as $row) {
        $t = (string) ($row['event_type'] ?? '');
        if ($t !== '') {
            $seen[$t] = true;
        }
    }
    $altMap = [
        'cpr.delete_live_complete' => ['cpr.delete_live_complete', 'cpr.deletelive_complete'],
        'cpr.import_live_complete' => ['cpr.import_live_complete', 'cpr.importlive_complete'],
        'cpr.special_live_complete' => ['cpr.special_live_complete', 'cpr.special_live_handler_complete'],
    ];
    $missing = [];
    foreach ($auditNeed as $ev) {
        $candidates = $altMap[$ev] ?? [$ev];
        $found = false;
        foreach ($candidates as $c) {
            if (!empty($seen[$c])) {
                $found = true;
                break;
            }
        }
        // special_live_complete is preferred; handler_complete alone is insufficient — require complete
        if ($ev === 'cpr.special_live_complete') {
            $found = !empty($seen['cpr.special_live_complete']);
        }
        if (!$found) {
            $missing[] = $ev;
        }
    }
    if ($missing === []) {
        $pass('audit_chain');
    } else {
        $fail('audit_chain', 'Missing audit events: ' . implode(',', $missing));
    }

    // No orphan P6 post-verify artifacts.
    if (is_dir($cprRoot . DIRECTORY_SEPARATOR . $jobId . DIRECTORY_SEPARATOR . 'post_verify')) {
        $fail('no_orphan_p6', 'Unexpected post_verify directory.');
    } else {
        $pass('no_orphan_p6');
    }

    // Replay / privilege refuse still hold on uploads engine.
    $replay = orange_cpr_uploads_live_run($env, $jobId, [
        'actor_admin_id' => (int) ($ctx['actor_admin_id'] ?? 0),
        'actor_is_super_admin' => true,
        'lease_token' => (string) ($ctx['lease_token'] ?? ''),
        'worker_id' => (string) ($ctx['worker_id'] ?? ''),
        'schema_revision' => 121,
        'force_replay' => true,
        'upload_manifest' => is_array($ctx['upload_manifest'] ?? null) ? $ctx['upload_manifest'] : [
            'manifest_id' => 'x', 'job_id' => $jobId, 'country_id' => 1,
            'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
            'schema_revision' => 121, 'paths' => [], 'manifest_sha256' => hash('sha256', '[]'),
        ],
        'upload_artifacts' => [],
    ]);
    if (empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_UPLOADSLIVE_ERR_REPLAY) {
        $pass('no_replay_path');
    } else {
        $fail('no_replay_path', 'force_replay must be refused; got ' . (string) ($replay['code'] ?? 'ok'));
    }

    $bypass = orange_cpr_uploads_live_refuse_unsafe(['force_pass' => true]);
    if (empty($bypass['ok'])) {
        $pass('no_privilege_bypass');
    } else {
        $fail('no_privilege_bypass', 'Unsafe knob not refused.');
    }

    $snap = orange_cpr_p5_control_plane_snapshot();
    if (empty($snap['p6_started']) && empty($snap['architecture_modified']) && empty($snap['owner_approved_modified'])) {
        $pass('control_plane_freeze_flags');
    } else {
        $fail('control_plane_freeze_flags', 'Control plane freeze flags invalid.');
    }

    $allOk = true;
    foreach ($checks as $c) {
        if (empty($c['ok'])) {
            $allOk = false;
            break;
        }
    }
    if (!$allOk) {
        return orange_cpr_p5int_fail(
            ORANGE_CPR_P5INT_ERR_VERIFY,
            'P5 integration verification failed (fail-closed).',
            ['checks' => $checks]
        );
    }

    return orange_cpr_p5int_ok([
        'checks' => $checks,
        'check_count' => count($checks),
        'audit_event_count' => count($audit),
        'job_state' => (string) ($job['state'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'session_full_backup_id' => (string) ($contract['session_full_backup_id'] ?? ''),
    ]);
}

/**
 * Execute full P5 PONR apply chain (via P4 Pre-PONR substrate) and seal freeze report.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_p5_integration_run(array $env, array $request = []): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_p5int_fail(ORANGE_CPR_P5INT_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_p5int_fail(
            ORANGE_CPR_P5INT_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during P5 integration.'
        );
    }
    if (empty($request['actor_is_super_admin']) || (int) ($request['actor_admin_id'] ?? 0) <= 0) {
        return orange_cpr_p5int_fail(ORANGE_CPR_P5INT_ERR_ACTOR, 'Super Admin actor required for P5 integration run.');
    }
    $forbidden = [
        'force_pass', 'bypass', 'execute_production_sql', 'start_p6', 'start_enterprise_audit',
        'create_git_tag', 'full_tree_replace', 'expand_scope', 'all_countries',
    ];
    foreach ($forbidden as $k) {
        if (!empty($request[$k])) {
            return orange_cpr_p5int_fail(ORANGE_CPR_P5INT_ERR_BYPASS, 'Forbidden integration knob: ' . $k);
        }
    }
    if (!empty($request['begin_p6']) || !empty($request['post_verify'])) {
        return orange_cpr_p5int_fail(ORANGE_CPR_P5INT_ERR_P6, 'P6 / post-verify forbidden in WP-P5-06.');
    }

    $actorAdminId = (int) $request['actor_admin_id'];
    $workerId = (string) ($request['worker_id'] ?? 'p5-int-worker');
    $stages = [];
    $cprRoot = orange_cpr_resolve_work_root($env);

    // Stage 0: frozen P4 Pre-PONR chain through CP-A (no new Pre-PONR business logic).
    $p4 = orange_cpr_p4_integration_run($env, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'worker_id' => $workerId,
    ]);
    if (empty($p4['ok'])) {
        return orange_cpr_p5int_fail(
            ORANGE_CPR_P5INT_ERR_CHAIN,
            'P4 Pre-PONR substrate failed: ' . (string) ($p4['code'] ?? ''),
            ['stage' => 'p4_pre_ponr_through_cpa', 'upstream' => $p4]
        );
    }
    $jobId = (string) ($p4['job_id'] ?? '');
    $lease = (string) ($p4['lease_token'] ?? '');
    $worker = (string) ($p4['worker_id'] ?? $workerId);
    $stages[] = ['stage' => 'p4_pre_ponr_through_cpa', 'ok' => true, 'job_id' => $jobId];

    $job = orange_cpr_job_read($cprRoot, $jobId);
    $fp = (string) ($job['package_fingerprint'] ?? '');
    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    $sessionId = (string) ($contract['session_full_backup_id'] ?? '');

    // DELETE → CP6
    $del = orange_cpr_delete_live_run($env, $jobId, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'target_slice' => orange_cpr_p5_integration_default_slice(),
    ]);
    if (empty($del['ok'])) {
        return orange_cpr_p5int_fail(
            ORANGE_CPR_P5INT_ERR_CHAIN,
            'DELETE failed: ' . (string) ($del['code'] ?? ''),
            ['stage' => 'target_slice_delete', 'upstream' => $del, 'stages' => $stages]
        );
    }
    $stages[] = ['stage' => 'target_slice_delete', 'ok' => true];
    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP6')) {
        return orange_cpr_p5int_fail(ORANGE_CPR_P5INT_ERR_CHAIN, 'CP6 missing after DELETE.', ['stages' => $stages]);
    }
    $stages[] = ['stage' => 'cp6', 'ok' => true];

    // IMPORT batches 1→6 → CP7
    $imp = orange_cpr_import_live_run($env, $jobId, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'session_full_backup_id' => $sessionId,
        'schema_revision' => 121,
    ]);
    if (empty($imp['ok'])) {
        return orange_cpr_p5int_fail(
            ORANGE_CPR_P5INT_ERR_CHAIN,
            'IMPORT failed: ' . (string) ($imp['code'] ?? ''),
            ['stage' => 'target_slice_import_batches_1_6', 'upstream' => $imp, 'stages' => $stages]
        );
    }
    $stages[] = ['stage' => 'target_slice_import_batches_1_6', 'ok' => true];
    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP7')) {
        return orange_cpr_p5int_fail(ORANGE_CPR_P5INT_ERR_CHAIN, 'CP7 missing after IMPORT.', ['stages' => $stages]);
    }
    $stages[] = ['stage' => 'cp7', 'ok' => true];

    // Special Handlers → CP8
    $special = orange_cpr_special_live_run($env, $jobId, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'run_all_handlers' => true,
        'sequence_package' => ['inv_c1' => 10, 'ord_c1' => 5, 'other_c2' => 99],
        'sequence_surviving' => ['inv_c1' => 12, 'ord_c1' => 3],
        'sequence_observed_max' => ['inv_c1' => 11, 'ord_c1' => 4],
    ]);
    if (empty($special['ok'])) {
        return orange_cpr_p5int_fail(
            ORANGE_CPR_P5INT_ERR_CHAIN,
            'Special Handlers failed: ' . (string) ($special['code'] ?? ''),
            ['stage' => 'special_handlers', 'upstream' => $special, 'stages' => $stages]
        );
    }
    $stages[] = ['stage' => 'special_handlers', 'ok' => true];
    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP8')) {
        return orange_cpr_p5int_fail(ORANGE_CPR_P5INT_ERR_CHAIN, 'CP8 missing after Special Handlers.', ['stages' => $stages]);
    }
    $stages[] = ['stage' => 'cp8', 'ok' => true];

    // Country Uploads Apply → CP9
    $paths = [
        'countries/kw/products/a.jpg',
        'countries/kw/products/b.jpg',
        'c1/docs/note.txt',
    ];
    $manifest = orange_cpr_p5_integration_build_upload_manifest($jobId, $fp, 1, 'KW', $paths);
    $uploads = orange_cpr_uploads_live_run($env, $jobId, [
        'actor_admin_id' => $actorAdminId,
        'actor_is_super_admin' => true,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'schema_revision' => 121,
        'upload_manifest' => $manifest,
        'upload_artifacts' => orange_cpr_p5_integration_upload_artifacts($manifest['paths'], 1),
    ]);
    if (empty($uploads['ok'])) {
        return orange_cpr_p5int_fail(
            ORANGE_CPR_P5INT_ERR_CHAIN,
            'Uploads Apply failed: ' . (string) ($uploads['code'] ?? ''),
            ['stage' => 'country_uploads_apply', 'upstream' => $uploads, 'stages' => $stages]
        );
    }
    $stages[] = ['stage' => 'country_uploads_apply', 'ok' => true];
    if (!orange_cpr_checkpoint_exists($cprRoot, $jobId, 'CP9')) {
        return orange_cpr_p5int_fail(ORANGE_CPR_P5INT_ERR_CHAIN, 'CP9 missing after Uploads.', ['stages' => $stages]);
    }
    $stages[] = ['stage' => 'cp9', 'ok' => true];

    $verify = orange_cpr_p5_integration_verify($env, $jobId, [
        'lease_token' => $lease,
        'worker_id' => $worker,
        'actor_admin_id' => $actorAdminId,
        'package_fingerprint' => $fp,
        'upload_manifest' => $manifest,
    ]);
    if (empty($verify['ok'])) {
        return array_merge($verify, [
            'stages' => $stages,
            'job_id' => $jobId,
            'lease_token' => $lease,
            'worker_id' => $worker,
        ]);
    }

    $now = gmdate('c');
    $reportId = orange_cpr_generate_job_id();
    $record = [
        'schema_version' => ORANGE_CPR_P5_INTEGRATION_SCHEMA,
        'engine_version' => ORANGE_CPR_P5_INTEGRATION_VERSION,
        'record_type' => 'p5_integration_verification',
        'integration_report_id' => $reportId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'stage_order' => orange_cpr_p5_integration_stage_order(),
        'stages' => $stages,
        'verification' => [
            'checks' => $verify['checks'] ?? [],
            'check_count' => (int) ($verify['check_count'] ?? 0),
            'audit_event_count' => (int) ($verify['audit_event_count'] ?? 0),
        ],
        'recovery_metadata' => [
            'job_state' => (string) ($verify['job_state'] ?? ''),
            'package_fingerprint' => (string) ($verify['package_fingerprint'] ?? ''),
            'country_id' => (int) ($verify['country_id'] ?? 0),
            'session_full_backup_id' => (string) ($verify['session_full_backup_id'] ?? ''),
            'completed_phase' => 'p5_production_apply',
            'execution_status' => 'p5_baseline_frozen',
            'ponr_crossed' => true,
            'production_sql_executed' => false,
            'production_uploads_mutated' => false,
            'primary_rollback' => 'session_full_backup_od_pin',
            'p6_not_started' => true,
        ],
        'package_fingerprint' => $fp,
        'actor_admin_id' => $actorAdminId,
        'actor_class' => 'super_admin',
        'ops_enablement_flag' => false,
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
        'p5_baseline_ready' => true,
        'p5_phase_complete' => true,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'p6_started' => false,
        'live_path' => true,
        'created_at' => $now,
    ];

    try {
        $path = orange_cpr_p5_integration_persist($cprRoot, $jobId, 'cpr_p5_integration_' . $reportId, $record);
        $latest = orange_cpr_p5_integration_latest_path($cprRoot, $jobId);
        $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
        $sealedLatest = orange_cpr_auth_seal(array_merge($record, ['integration_report_path' => $path]));
        $json = json_encode($sealedLatest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('P5 integration latest pointer write failed.');
        }
        orange_cpr_atomic_rename_replace($tmp, $latest);
    } catch (RuntimeException $e) {
        return orange_cpr_p5int_fail(ORANGE_CPR_P5INT_ERR_PERSIST, $e->getMessage(), ['stages' => $stages]);
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.p5_integration_verify',
        'job_id' => $jobId,
        'integration_report_id' => $reportId,
        'p5_baseline_ready' => true,
        'actor_admin_id' => $actorAdminId,
        'ponr_crossed' => true,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'enablement_flag_observed' => false,
        'p6_started' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
    ]);

    return orange_cpr_p5int_ok([
        'message' => 'P5 Production Apply chain verified through CP9; baseline frozen; P6 not started.',
        'job_id' => $jobId,
        'integration_report_id' => $reportId,
        'integration_report_path' => $path,
        'integration_report' => orange_cpr_p5_integration_load_latest($cprRoot, $jobId),
        'stages' => $stages,
        'verification' => $verify,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'package_fingerprint' => $fp,
        'p5_baseline_ready' => true,
        'p5_phase_complete' => true,
        'enablement_flag_observed' => false,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
    ]);
}
