<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Session Full-Anchor Rollback (WP-P6-04 / OD-ROLLBACK).
 * Run: php scripts/backup/country_production/self_test_cpr_rollback_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_rollback_live.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p5_integration.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p6_control_plane.php';

$pass = 0;
$fail = 0;

function cpr_rb(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        ++$pass;
        echo "PASS  {$name}\n";
    } else {
        ++$fail;
        echo "FAIL  {$name}" . ($detail !== '' ? " â€” {$detail}" : '') . "\n";
    }
}

/**
 * @return array{env:array<string,mixed>,cpr:string}
 */
function cpr_rb_fresh_env(string $base): array
{
    $id = bin2hex(random_bytes(3));
    $restoreWork = $base . DIRECTORY_SEPARATOR . 'rw_' . $id;
    $cpr = $restoreWork . DIRECTORY_SEPARATOR . 'country_production';
    $shadow = $restoreWork . DIRECTORY_SEPARATOR . 'country_shadow';
    $backupRoot = $base . DIRECTORY_SEPARATOR . 'br_' . $id;
    @mkdir($cpr, 0775, true);
    @mkdir($shadow, 0775, true);
    @mkdir($backupRoot . DIRECTORY_SEPARATOR . 'locks', 0775, true);

    return [
        'cpr' => $cpr,
        'env' => [
            'ORANGE_CPR_WORK_DIR' => $cpr,
            'ORANGE_RESTORE_WORK_DIR' => $restoreWork,
            'ORANGE_BACKUP_ROOT' => $backupRoot,
            'ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false,
        ],
    ];
}

function cpr_rb_cleanup(string $base): void
{
    if (!is_dir($base)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($base);
}

/**
 * P5 through CP9 + Post-Verify FAIL â†’ cpr_paused_verify_failed.
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function cpr_rb_setup_paused_verify(array $env): array
{
    $p5 = orange_cpr_p5_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'worker_id' => 'rb-worker',
    ]);
    if (empty($p5['ok'])) {
        throw new RuntimeException('p5: ' . (string) ($p5['code'] ?? $p5['message'] ?? ''));
    }
    $jid = (string) ($p5['job_id'] ?? '');
    $lease = (string) ($p5['lease_token'] ?? '');
    $worker = (string) ($p5['worker_id'] ?? 'rb-worker');
    $pv = orange_cpr_post_verify_live_run($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'schema_revision' => 121,
        'force_fail_check' => 'V02',
        'inject_survivor_hash' => str_repeat('x', 32),
    ]);
    if (!empty($pv['ok']) || ($pv['code'] ?? '') !== ORANGE_CPR_PVLIVE_ERR_SUITE) {
        throw new RuntimeException('expected post_verify FAIL: ' . (string) ($pv['code'] ?? ''));
    }
    $cprRoot = orange_cpr_resolve_work_root($env);
    $job = orange_cpr_job_read($cprRoot, $jid);
    if ((string) ($job['state'] ?? '') !== 'cpr_paused_verify_failed') {
        throw new RuntimeException('expected paused_verify_failed, got ' . (string) ($job['state'] ?? ''));
    }

    return [
        'job_id' => $jid,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'cpr_root' => $cprRoot,
        'package_fingerprint' => (string) ($p5['package_fingerprint'] ?? ''),
        'session_full_backup_id' => (string) (orange_cpr_contract_read($cprRoot, $jid)['session_full_backup_id'] ?? ''),
        'session_full_backup_fingerprint' => (string) (orange_cpr_contract_read($cprRoot, $jid)['session_full_backup_fingerprint'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $setup
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function cpr_rb_req(array $setup, array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setup['lease_token'],
        'worker_id' => (string) $setup['worker_id'],
        'schema_revision' => 121,
        'confirmation_phrase' => 'RESTORE',
        'reauth_ok' => true,
        'password_reauth_ok' => true,
    ], $extra);
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p604_' . bin2hex(random_bytes(4));
@mkdir($base, 0775, true);

try {
    cpr_rb('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P9-02-enablement-preconditions');
    cpr_rb('dirname_constant', ORANGE_CPR_ROLLBACK_DIRNAME === 'rollback');
    cpr_rb(
        'control_plane_flag',
        !empty(orange_cpr_p6_control_plane_snapshot()['rollback_integration_engine_implemented'])
    );
    cpr_rb(
        'design_stop_rule_present',
        str_contains(
            (string) file_get_contents(
                dirname(__DIR__, 3) . '/docs/backup/COUNTRY_PRODUCTION_RESTORE_P6_04_ROLLBACK_INTEGRATION.md'
            ),
            'Do **not** begin **WP-P6-05**'
        )
    );

    // --- Valid rollback ---
    $ctx = cpr_rb_fresh_env($base);
    $env = $ctx['env'];
    $setup = cpr_rb_setup_paused_verify($env);
    $jid = (string) $setup['job_id'];
    $cprRoot = (string) $setup['cpr_root'];
    $run = orange_cpr_rollback_live_run($env, $jid, cpr_rb_req($setup));
    cpr_rb('valid_rollback', !empty($run['ok']), (string) ($run['code'] ?? ''));
    cpr_rb('state_rollback_completed', (string) (orange_cpr_job_read($cprRoot, $jid)['state'] ?? '') === 'cpr_rollback_completed');
    $report = orange_cpr_rollback_live_load_latest($cprRoot, $jid, 'report');
    $manifest = orange_cpr_rollback_live_load_latest($cprRoot, $jid, 'manifest');
    $evidence = orange_cpr_rollback_live_load_latest($cprRoot, $jid, 'evidence');
    cpr_rb(
        'sealed_rollback_report',
        is_array($report) && !empty($report['rollback_completed']) && orange_cpr_auth_verify_seal($report)
    );
    cpr_rb(
        'rollback_manifest_integrity',
        is_array($manifest)
        && !empty($manifest['restore_complete'])
        && ($manifest['partial_rollback'] ?? true) === false
        && orange_cpr_auth_verify_seal($manifest)
        && (string) ($manifest['manifest_fingerprint'] ?? '') !== ''
    );
    cpr_rb(
        'recovery_evidence',
        is_array($evidence) && !empty($evidence['restore_complete']) && orange_cpr_auth_verify_seal($evidence)
    );
    cpr_rb('no_production_sql', ($run['production_sql_executed'] ?? true) === false);
    cpr_rb('no_upload_mutation', ($run['production_uploads_mutated'] ?? true) === false);
    cpr_rb('proof_no_production_mutation', ($run['production_mutation'] ?? true) === false);
    cpr_rb('partial_forbidden_flag', ($run['partial_rollback'] ?? true) === false);
    $maint = orange_cpr_maint_live_load_state($cprRoot, $jid);
    cpr_rb(
        'proof_maintenance_remains_active',
        is_array($maint) && !empty($maint['global_maintenance_on']) && !orange_cpr_checkpoint_exists($cprRoot, $jid, 'CP12')
    );
    $audit = (string) file_get_contents($cprRoot . DIRECTORY_SEPARATOR . $jid . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME);
    cpr_rb(
        'audit_integrity',
        str_contains($audit, 'cpr.rollback_live_authorization')
        && str_contains($audit, 'cpr.rollback_live_complete')
    );

    // Idempotent + replay refuse
    $again = orange_cpr_rollback_live_run($env, $jid, cpr_rb_req($setup));
    cpr_rb('idempotent_complete', !empty($again['ok']) && !empty($again['idempotent']));
    $replay = orange_cpr_rollback_live_run($env, $jid, cpr_rb_req($setup, ['force_replay' => true]));
    cpr_rb('replay_attempt', empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_RBLIVE_ERR_REPLAY);

    // --- Invalid rollback state ---
    $ctx2 = cpr_rb_fresh_env($base);
    $setup2 = cpr_rb_setup_paused_verify($ctx2['env']);
    $job2 = orange_cpr_job_read($setup2['cpr_root'], $setup2['job_id']);
    $job2['state'] = 'cpr_succeeded';
    orange_cpr_job_write($setup2['cpr_root'], $setup2['job_id'], $job2);
    $badState = orange_cpr_rollback_live_run($ctx2['env'], (string) $setup2['job_id'], cpr_rb_req($setup2));
    cpr_rb(
        'invalid_rollback_state',
        empty($badState['ok']) && ($badState['code'] ?? '') === ORANGE_CPR_RBLIVE_ERR_ELIGIBLE,
        (string) ($badState['code'] ?? '')
    );

    // --- Missing recovery metadata ---
    $ctx3 = cpr_rb_fresh_env($base);
    $setup3 = cpr_rb_setup_paused_verify($ctx3['env']);
    $vr = orange_cpr_post_verify_live_load_latest($setup3['cpr_root'], $setup3['job_id'], 'report');
    if (is_array($vr)) {
        unset($vr['recovery_metadata'], $vr['seal'], $vr['content_sha256'], $vr['sealed_sha256']);
        $path = orange_cpr_post_verify_live_latest_path($setup3['cpr_root'], $setup3['job_id'], 'report');
        $tmp = $path . '.tmp';
        file_put_contents($tmp, (string) json_encode(orange_cpr_auth_seal($vr), JSON_UNESCAPED_UNICODE));
        orange_cpr_atomic_rename_replace($tmp, $path);
    }
    $missMeta = orange_cpr_rollback_live_run($ctx3['env'], (string) $setup3['job_id'], cpr_rb_req($setup3));
    cpr_rb(
        'missing_recovery_metadata',
        empty($missMeta['ok']) && ($missMeta['code'] ?? '') === ORANGE_CPR_RBLIVE_ERR_RECOVERY,
        (string) ($missMeta['code'] ?? '')
    );

    // --- Corrupt checkpoint ---
    $ctx4 = cpr_rb_fresh_env($base);
    $setup4 = cpr_rb_setup_paused_verify($ctx4['env']);
    $job4 = orange_cpr_job_read($setup4['cpr_root'], $setup4['job_id']);
    $cpId = (string) ($job4['last_checkpoint_id'] ?? 'CP9');
    $cpPath = orange_cpr_checkpoint_final_path($setup4['cpr_root'], $setup4['job_id'], $cpId);
    if (is_file($cpPath)) {
        $raw = (string) file_get_contents($cpPath);
        $cp = json_decode($raw, true);
        if (is_array($cp)) {
            $cp['content_sha256'] = str_repeat('0', 64);
            file_put_contents($cpPath, (string) json_encode($cp, JSON_UNESCAPED_UNICODE));
        }
    }
    $corrupt = orange_cpr_rollback_live_run($ctx4['env'], (string) $setup4['job_id'], cpr_rb_req($setup4));
    cpr_rb(
        'corrupt_checkpoint',
        empty($corrupt['ok']) && ($corrupt['code'] ?? '') === ORANGE_CPR_RBLIVE_ERR_CHECKPOINT,
        (string) ($corrupt['code'] ?? '')
    );

    // --- Contract / schema mismatch ---
    $ctx5 = cpr_rb_fresh_env($base);
    $setup5 = cpr_rb_setup_paused_verify($ctx5['env']);
    $sch = orange_cpr_rollback_live_run($ctx5['env'], (string) $setup5['job_id'], cpr_rb_req($setup5, [
        'schema_revision' => 999,
    ]));
    cpr_rb('contract_mismatch', empty($sch['ok']) && ($sch['code'] ?? '') === ORANGE_CPR_RBLIVE_ERR_SCHEMA);

    // --- Cross-country rollback attempt ---
    $ctx6 = cpr_rb_fresh_env($base);
    $setup6 = cpr_rb_setup_paused_verify($ctx6['env']);
    $cty = orange_cpr_rollback_live_run($ctx6['env'], (string) $setup6['job_id'], cpr_rb_req($setup6, [
        'country_id' => 99,
    ]));
    cpr_rb('cross_country_rollback_attempt', empty($cty['ok']) && ($cty['code'] ?? '') === ORANGE_CPR_RBLIVE_ERR_COUNTRY);

    // --- Recovery boundary mismatch ---
    $ctx7 = cpr_rb_fresh_env($base);
    $setup7 = cpr_rb_setup_paused_verify($ctx7['env']);
    $bnd = orange_cpr_rollback_live_run($ctx7['env'], (string) $setup7['job_id'], cpr_rb_req($setup7, [
        'session_full_backup_id' => 'not-the-session-pin',
    ]));
    cpr_rb(
        'recovery_boundary_mismatch',
        empty($bnd['ok']) && ($bnd['code'] ?? '') === ORANGE_CPR_RBLIVE_ERR_BOUNDARY,
        (string) ($bnd['code'] ?? '')
    );

    // Privilege / auto / phrase
    $ctx8 = cpr_rb_fresh_env($base);
    $setup8 = cpr_rb_setup_paused_verify($ctx8['env']);
    $bypass = orange_cpr_rollback_live_run($ctx8['env'], (string) $setup8['job_id'], cpr_rb_req($setup8, [
        'force_pass' => true,
    ]));
    cpr_rb('privilege_bypass_denied', empty($bypass['ok']) && ($bypass['code'] ?? '') === ORANGE_CPR_RBLIVE_ERR_BYPASS);
    $auto = orange_cpr_rollback_live_run($ctx8['env'], (string) $setup8['job_id'], cpr_rb_req($setup8, [
        'auto_rollback' => true,
    ]));
    cpr_rb('auto_rollback_denied', empty($auto['ok']) && ($auto['code'] ?? '') === ORANGE_CPR_RBLIVE_ERR_AUTO);
    $phrase = orange_cpr_rollback_live_run($ctx8['env'], (string) $setup8['job_id'], cpr_rb_req($setup8, [
        'confirmation_phrase' => 'WRONG',
    ]));
    cpr_rb('phrase_required', empty($phrase['ok']) && ($phrase['code'] ?? '') === ORANGE_CPR_RBLIVE_ERR_PHRASE);
    $maintKnob = orange_cpr_rollback_live_run($ctx8['env'], (string) $setup8['job_id'], cpr_rb_req($setup8, [
        'release_maint' => true,
    ]));
    cpr_rb('maint_release_knob_denied', empty($maintKnob['ok']) && ($maintKnob['code'] ?? '') === ORANGE_CPR_RBLIVE_ERR_RELEASE);

    $docs = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup';
    $design = $docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P6_04_ROLLBACK_INTEGRATION.md';
    $index = (string) file_get_contents($docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P6_ARTIFACT_INDEX.md');
    cpr_rb('design_doc_exists', is_file($design));
    cpr_rb(
        'index_wp_p6_04_complete',
        str_contains($index, 'COUNTRY_PRODUCTION_RESTORE_P6_04_ROLLBACK_INTEGRATION.md')
        && str_contains($index, '**WP-P6-04 COMPLETE**')
    );
    cpr_rb('design_stop_blocks_p6_05', str_contains(
        (string) file_get_contents($design),
        'Do **not** begin **WP-P6-05**'
    ));
    cpr_rb('ops_enablement_still_false', orange_cpr_enablement_flag_read($env) === false);
} catch (Throwable $e) {
    cpr_rb('exception', false, $e->getMessage());
} finally {
    cpr_rb_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
