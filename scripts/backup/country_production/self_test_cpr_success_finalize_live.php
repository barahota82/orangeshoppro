<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Success Finalize (WP-P6-03 / CP11).
 * Run: php scripts/backup/country_production/self_test_cpr_success_finalize_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_success_finalize_live.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p5_integration.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p6_control_plane.php';

$pass = 0;
$fail = 0;

function cpr_sf(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        ++$pass;
        echo "PASS  {$name}\n";
    } else {
        ++$fail;
        echo "FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/**
 * @return array{env:array<string,mixed>,cpr:string}
 */
function cpr_sf_fresh_env(string $base): array
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

function cpr_sf_cleanup(string $base): void
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
 * P5 through CP9 + Post-Verify CP10.
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function cpr_sf_setup_through_cp10(array $env): array
{
    $p5 = orange_cpr_p5_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'worker_id' => 'sf-worker',
    ]);
    if (empty($p5['ok'])) {
        throw new RuntimeException('p5: ' . (string) ($p5['code'] ?? $p5['message'] ?? ''));
    }
    $jid = (string) ($p5['job_id'] ?? '');
    $lease = (string) ($p5['lease_token'] ?? '');
    $worker = (string) ($p5['worker_id'] ?? 'sf-worker');
    $pv = orange_cpr_post_verify_live_run($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'schema_revision' => 121,
    ]);
    if (empty($pv['ok'])) {
        throw new RuntimeException('post_verify: ' . (string) ($pv['code'] ?? $pv['message'] ?? ''));
    }

    return [
        'job_id' => $jid,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'cpr_root' => orange_cpr_resolve_work_root($env),
        'package_fingerprint' => (string) ($p5['package_fingerprint'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $setup
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function cpr_sf_req(array $setup, array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setup['lease_token'],
        'worker_id' => (string) $setup['worker_id'],
        'schema_revision' => 121,
    ], $extra);
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p603_' . bin2hex(random_bytes(4));
@mkdir($base, 0775, true);

try {
    cpr_sf('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P7-04-evidence-pack');
    cpr_sf('dirname_constant', ORANGE_CPR_SUCCESS_FINALIZE_DIRNAME === 'success_finalize');
    cpr_sf(
        'control_plane_flag',
        !empty(orange_cpr_p6_control_plane_snapshot()['success_finalize_engine_implemented'])
    );

    // --- Valid Success Finalize ---
    $ctx = cpr_sf_fresh_env($base);
    $env = $ctx['env'];
    $setup = cpr_sf_setup_through_cp10($env);
    $jid = (string) $setup['job_id'];
    $cprRoot = (string) $setup['cpr_root'];
    $run = orange_cpr_success_finalize_live_run($env, $jid, cpr_sf_req($setup));
    cpr_sf('valid_success_finalize', !empty($run['ok']), (string) ($run['code'] ?? ''));
    cpr_sf('cp11_exists', orange_cpr_checkpoint_exists($cprRoot, $jid, 'CP11'));
    $report = orange_cpr_success_finalize_live_load_latest($cprRoot, $jid, 'report');
    $manifest = orange_cpr_success_finalize_live_load_latest($cprRoot, $jid, 'manifest');
    cpr_sf(
        'sealed_success_report',
        is_array($report) && !empty($report['success_finalized']) && orange_cpr_auth_verify_seal($report)
    );
    cpr_sf(
        'sealed_completion_manifest',
        is_array($manifest) && !empty($manifest['reports_sealed']) && orange_cpr_auth_verify_seal($manifest)
    );
    cpr_sf('state_succeeded', (string) (orange_cpr_job_read($cprRoot, $jid)['state'] ?? '') === 'cpr_succeeded');
    cpr_sf('no_production_sql', ($run['production_sql_executed'] ?? true) === false);
    cpr_sf('no_upload_mutation', ($run['production_uploads_mutated'] ?? true) === false);
    cpr_sf('rollback_not_executed', ($run['rollback_executed'] ?? true) === false);
    cpr_sf('maint_not_released_flag', ($run['maintenance_released'] ?? true) === false);
    $maint = orange_cpr_maint_live_load_state($cprRoot, $jid);
    cpr_sf(
        'proof_maintenance_remains_active',
        is_array($maint) && !empty($maint['global_maintenance_on']) && !orange_cpr_checkpoint_exists($cprRoot, $jid, 'CP12')
    );
    cpr_sf(
        'recovery_metadata_integrity',
        is_array($run['recovery_metadata'] ?? null)
        && ($run['recovery_metadata']['maint_remains_on'] ?? false) === true
        && ($run['recovery_metadata']['rollback_executed'] ?? true) === false
        && ($run['recovery_metadata']['auto_rollback'] ?? true) === false
    );
    $cp11 = orange_cpr_checkpoint_load($env, $jid, 'CP11');
    $cp11Payload = is_array($cp11['checkpoint']['payload'] ?? null) ? $cp11['checkpoint']['payload'] : [];
    cpr_sf(
        'cp11_integrity',
        !empty($cp11['ok'])
        && ($cp11Payload['reports_sealed'] ?? false) === true
        && is_array($cp11Payload['report_ids'] ?? null)
    );
    $audit = (string) file_get_contents($cprRoot . DIRECTORY_SEPARATOR . $jid . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME);
    cpr_sf('audit_integrity', str_contains($audit, 'cpr.success_finalize_live_complete'));
    cpr_sf('proof_rollback_not_in_audit', !str_contains($audit, 'cpr.rollback') && !str_contains($audit, 'rollback_action'));

    // Idempotent + replay refuse
    $again = orange_cpr_success_finalize_live_run($env, $jid, cpr_sf_req($setup));
    cpr_sf('idempotent_complete', !empty($again['ok']) && !empty($again['idempotent']));
    $replay = orange_cpr_success_finalize_live_run($env, $jid, cpr_sf_req($setup, ['force_replay' => true]));
    cpr_sf('replay_attempt', empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_SFLIVE_ERR_REPLAY);

    // --- Missing CP10 ---
    $ctx2 = cpr_sf_fresh_env($base);
    $setup2 = cpr_sf_setup_through_cp10($ctx2['env']);
    @unlink(orange_cpr_checkpoint_final_path($setup2['cpr_root'], $setup2['job_id'], 'CP10'));
    $miss = orange_cpr_success_finalize_live_run($ctx2['env'], (string) $setup2['job_id'], cpr_sf_req($setup2));
    cpr_sf('missing_cp10', empty($miss['ok']) && ($miss['code'] ?? '') === ORANGE_CPR_SFLIVE_ERR_CP10, (string) ($miss['code'] ?? ''));

    // --- Contract / schema mismatch ---
    $ctx3 = cpr_sf_fresh_env($base);
    $setup3 = cpr_sf_setup_through_cp10($ctx3['env']);
    $sch = orange_cpr_success_finalize_live_run($ctx3['env'], (string) $setup3['job_id'], cpr_sf_req($setup3, [
        'schema_revision' => 999,
    ]));
    cpr_sf('contract_mismatch', empty($sch['ok']) && ($sch['code'] ?? '') === ORANGE_CPR_SFLIVE_ERR_SCHEMA);

    // --- State mismatch ---
    $ctx4 = cpr_sf_fresh_env($base);
    $setup4 = cpr_sf_setup_through_cp10($ctx4['env']);
    $job4 = orange_cpr_job_read($setup4['cpr_root'], $setup4['job_id']);
    $job4['state'] = 'cpr_uploads_applying';
    orange_cpr_job_write($setup4['cpr_root'], $setup4['job_id'], $job4);
    $st = orange_cpr_success_finalize_live_run($ctx4['env'], (string) $setup4['job_id'], cpr_sf_req($setup4));
    cpr_sf('state_mismatch', empty($st['ok']) && ($st['code'] ?? '') === ORANGE_CPR_SFLIVE_ERR_STATE);

    // --- Rollback / maint release knobs refused ---
    $ctx5 = cpr_sf_fresh_env($base);
    $setup5 = cpr_sf_setup_through_cp10($ctx5['env']);
    $rb = orange_cpr_success_finalize_live_run($ctx5['env'], (string) $setup5['job_id'], cpr_sf_req($setup5, [
        'rollback' => true,
    ]));
    cpr_sf('rollback_knob_denied', empty($rb['ok']) && ($rb['code'] ?? '') === ORANGE_CPR_SFLIVE_ERR_ROLLBACK);
    $rel = orange_cpr_success_finalize_live_run($ctx5['env'], (string) $setup5['job_id'], cpr_sf_req($setup5, [
        'release_maint' => true,
    ]));
    cpr_sf('maint_release_knob_denied', empty($rel['ok']) && ($rel['code'] ?? '') === ORANGE_CPR_SFLIVE_ERR_RELEASE);

    $docs = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup';
    $design = $docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P6_03_SUCCESS_FINALIZE.md';
    $index = (string) file_get_contents($docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P6_ARTIFACT_INDEX.md');
    cpr_sf('design_doc_exists', is_file($design));
    cpr_sf(
        'index_wp_p6_03_complete',
        str_contains($index, 'COUNTRY_PRODUCTION_RESTORE_P6_03_SUCCESS_FINALIZE.md')
        && str_contains($index, '**WP-P6-03 COMPLETE**')
    );
    cpr_sf('design_stop_rule_present', str_contains(
        (string) file_get_contents($design),
        'Do **not** begin **WP-P6-04**'
    ));
    cpr_sf('ops_enablement_still_false', orange_cpr_enablement_flag_read($env) === false);
} catch (Throwable $e) {
    cpr_sf('exception', false, $e->getMessage());
} finally {
    cpr_sf_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
