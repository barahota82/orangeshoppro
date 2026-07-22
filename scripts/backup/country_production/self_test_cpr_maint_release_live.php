<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live GLOBAL Maintenance Release (WP-P6-05 / CP12).
 * Run: php scripts/backup/country_production/self_test_cpr_maint_release_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_maint_release_live.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p5_integration.php';
require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p6_control_plane.php';

$pass = 0;
$fail = 0;

function cpr_mr(string $name, bool $ok, string $detail = ''): void
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
function cpr_mr_fresh_env(string $base): array
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

function cpr_mr_cleanup(string $base): void
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
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function cpr_mr_setup_through_cp11(array $env): array
{
    $p5 = orange_cpr_p5_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'worker_id' => 'mr-worker',
    ]);
    if (empty($p5['ok'])) {
        throw new RuntimeException('p5: ' . (string) ($p5['code'] ?? $p5['message'] ?? ''));
    }
    $jid = (string) ($p5['job_id'] ?? '');
    $lease = (string) ($p5['lease_token'] ?? '');
    $worker = (string) ($p5['worker_id'] ?? 'mr-worker');
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
    $sf = orange_cpr_success_finalize_live_run($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'schema_revision' => 121,
    ]);
    if (empty($sf['ok'])) {
        throw new RuntimeException('success_finalize: ' . (string) ($sf['code'] ?? $sf['message'] ?? ''));
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
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function cpr_mr_setup_through_rollback(array $env): array
{
    $p5 = orange_cpr_p5_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'worker_id' => 'mr-rb-worker',
    ]);
    if (empty($p5['ok'])) {
        throw new RuntimeException('p5: ' . (string) ($p5['code'] ?? $p5['message'] ?? ''));
    }
    $jid = (string) ($p5['job_id'] ?? '');
    $lease = (string) ($p5['lease_token'] ?? '');
    $worker = (string) ($p5['worker_id'] ?? 'mr-rb-worker');
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
        throw new RuntimeException('expected post_verify FAIL');
    }
    $rb = orange_cpr_rollback_live_run($env, $jid, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => $lease,
        'worker_id' => $worker,
        'schema_revision' => 121,
        'confirmation_phrase' => 'RESTORE',
        'reauth_ok' => true,
        'password_reauth_ok' => true,
    ]);
    if (empty($rb['ok'])) {
        throw new RuntimeException('rollback: ' . (string) ($rb['code'] ?? $rb['message'] ?? ''));
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
function cpr_mr_req(array $setup, array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) $setup['lease_token'],
        'worker_id' => (string) $setup['worker_id'],
        'schema_revision' => 121,
        'runbook_completed' => true,
        'runbook_evidence_ref' => 'runbook/evidence/p605-1',
        'write_block_cleared_proof' => 'write_block_cleared:sa-p605-1',
    ], $extra);
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p605_' . bin2hex(random_bytes(4));
@mkdir($base, 0775, true);

try {
    cpr_mr('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P6-05-maint-release');
    cpr_mr('dirname_constant', ORANGE_CPR_MAINT_RELEASE_DIRNAME === 'maint_release');
    cpr_mr(
        'control_plane_flag',
        !empty(orange_cpr_p6_control_plane_snapshot()['maint_release_engine_implemented'])
    );
    cpr_mr(
        'control_plane_no_p6_integration_yet',
        empty(orange_cpr_p6_control_plane_snapshot()['p6_integration_baseline_complete'])
    );

    // --- Valid release after CP11 ---
    $ctx = cpr_mr_fresh_env($base);
    $env = $ctx['env'];
    $setup = cpr_mr_setup_through_cp11($env);
    $jid = (string) $setup['job_id'];
    $cprRoot = (string) $setup['cpr_root'];
    $run = orange_cpr_maint_release_live_run($env, $jid, cpr_mr_req($setup));
    cpr_mr('valid_release_after_cp11', !empty($run['ok']), (string) ($run['code'] ?? ''));
    cpr_mr('cp12_exists', orange_cpr_checkpoint_exists($cprRoot, $jid, 'CP12'));
    cpr_mr('state_maintenance_released', (string) (orange_cpr_job_read($cprRoot, $jid)['state'] ?? '') === 'cpr_maintenance_released');
    $report = orange_cpr_maint_release_live_load_latest($cprRoot, $jid, 'report');
    $manifest = orange_cpr_maint_release_live_load_latest($cprRoot, $jid, 'manifest');
    cpr_mr(
        'sealed_release_report',
        is_array($report) && !empty($report['maintenance_released']) && orange_cpr_auth_verify_seal($report)
    );
    cpr_mr(
        'sealed_release_manifest',
        is_array($manifest) && !empty($manifest['writers_restored']) && orange_cpr_auth_verify_seal($manifest)
    );
    $maint = orange_cpr_maint_live_load_state($cprRoot, $jid);
    cpr_mr('maint_off_after_release', is_array($maint) && empty($maint['global_maintenance_on']));
    cpr_mr('no_production_sql', ($run['production_sql_executed'] ?? true) === false);
    cpr_mr('proof_no_production_mutation', ($run['production_mutation'] ?? true) === false
        && ($run['production_uploads_mutated'] ?? true) === false);
    $cp12 = orange_cpr_checkpoint_load($env, $jid, 'CP12');
    $cp12Payload = is_array($cp12['checkpoint']['payload'] ?? null) ? $cp12['checkpoint']['payload'] : [];
    cpr_mr(
        'cp12_integrity',
        !empty($cp12['ok'])
        && ($cp12Payload['runbook_completed'] ?? false) === true
        && ($cp12Payload['writers_restored'] ?? false) === true
        && ($cp12Payload['prior_terminal'] ?? '') === 'cpr_succeeded'
    );
    $audit = (string) file_get_contents($cprRoot . DIRECTORY_SEPARATOR . $jid . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME);
    cpr_mr(
        'audit_integrity',
        str_contains($audit, 'cpr.maint_release_live_start')
        && str_contains($audit, 'cpr.maint_release_live_complete')
    );

    // Exactly once + replay refuse
    $again = orange_cpr_maint_release_live_run($env, $jid, cpr_mr_req($setup));
    cpr_mr(
        'proof_release_exactly_once',
        !empty($again['ok']) && !empty($again['idempotent']) && !empty($again['exactly_once'])
    );
    $replay = orange_cpr_maint_release_live_run($env, $jid, cpr_mr_req($setup, ['force_replay' => true]));
    cpr_mr('replay_attempt', empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_MRLIVE_ERR_REPLAY);

    // --- Valid release after approved rollback ---
    $ctxRb = cpr_mr_fresh_env($base);
    $setupRb = cpr_mr_setup_through_rollback($ctxRb['env']);
    $runRb = orange_cpr_maint_release_live_run($ctxRb['env'], (string) $setupRb['job_id'], cpr_mr_req($setupRb));
    cpr_mr('valid_release_after_rollback', !empty($runRb['ok']), (string) ($runRb['code'] ?? ''));
    cpr_mr(
        'cp12_after_rollback',
        orange_cpr_checkpoint_exists($setupRb['cpr_root'], $setupRb['job_id'], 'CP12')
    );
    $cp12Rb = orange_cpr_checkpoint_load($ctxRb['env'], (string) $setupRb['job_id'], 'CP12');
    $cp12RbPayload = is_array($cp12Rb['checkpoint']['payload'] ?? null) ? $cp12Rb['checkpoint']['payload'] : [];
    cpr_mr(
        'cp12_prior_terminal_rollback',
        ($cp12RbPayload['prior_terminal'] ?? '') === 'cpr_rollback_completed'
    );

    // --- Missing CP11 / rollback completion ---
    $ctx2 = cpr_mr_fresh_env($base);
    $p5miss = orange_cpr_p5_integration_run($ctx2['env'], [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'worker_id' => 'mr-miss',
    ]);
    $jid2 = (string) ($p5miss['job_id'] ?? '');
    $miss = orange_cpr_maint_release_live_run($ctx2['env'], $jid2, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'lease_token' => (string) ($p5miss['lease_token'] ?? ''),
        'worker_id' => (string) ($p5miss['worker_id'] ?? 'mr-miss'),
        'schema_revision' => 121,
        'runbook_completed' => true,
        'runbook_evidence_ref' => 'rb',
        'write_block_cleared_proof' => 'cleared',
    ]);
    cpr_mr(
        'missing_cp11_or_rollback_completion',
        empty($miss['ok']) && in_array($miss['code'] ?? '', [
            ORANGE_CPR_MRLIVE_ERR_TERMINAL,
            ORANGE_CPR_MRLIVE_ERR_CP11,
            ORANGE_CPR_MRLIVE_ERR_ROLLBACK,
        ], true),
        (string) ($miss['code'] ?? '')
    );

    // --- Contract mismatch ---
    $ctx3 = cpr_mr_fresh_env($base);
    $setup3 = cpr_mr_setup_through_cp11($ctx3['env']);
    $sch = orange_cpr_maint_release_live_run($ctx3['env'], (string) $setup3['job_id'], cpr_mr_req($setup3, [
        'schema_revision' => 999,
    ]));
    cpr_mr('contract_mismatch', empty($sch['ok']) && ($sch['code'] ?? '') === ORANGE_CPR_MRLIVE_ERR_SCHEMA);

    // --- State mismatch ---
    $ctx4 = cpr_mr_fresh_env($base);
    $setup4 = cpr_mr_setup_through_cp11($ctx4['env']);
    $job4 = orange_cpr_job_read($setup4['cpr_root'], $setup4['job_id']);
    $job4['state'] = 'cpr_post_verifying';
    orange_cpr_job_write($setup4['cpr_root'], $setup4['job_id'], $job4);
    $st = orange_cpr_maint_release_live_run($ctx4['env'], (string) $setup4['job_id'], cpr_mr_req($setup4));
    cpr_mr('state_mismatch', empty($st['ok']) && ($st['code'] ?? '') === ORANGE_CPR_MRLIVE_ERR_TERMINAL);

    // --- Authority mismatch ---
    $ctx5 = cpr_mr_fresh_env($base);
    $setup5 = cpr_mr_setup_through_cp11($ctx5['env']);
    $authFile = orange_cpr_auth_live_latest_path($setup5['cpr_root'], $setup5['job_id']);
    if (is_file($authFile)) {
        $auth = json_decode((string) file_get_contents($authFile), true);
        if (is_array($auth)) {
            $auth['ponr_authorized'] = false;
            unset($auth['seal'], $auth['content_sha256'], $auth['sealed_sha256']);
            $tmp = $authFile . '.tmp';
            file_put_contents($tmp, (string) json_encode(orange_cpr_auth_seal($auth), JSON_UNESCAPED_UNICODE));
            orange_cpr_atomic_rename_replace($tmp, $authFile);
        }
    }
    $authMiss = orange_cpr_maint_release_live_run($ctx5['env'], (string) $setup5['job_id'], cpr_mr_req($setup5));
    cpr_mr(
        'authority_mismatch',
        empty($authMiss['ok']) && ($authMiss['code'] ?? '') === ORANGE_CPR_MRLIVE_ERR_AUTHORITY,
        (string) ($authMiss['code'] ?? '')
    );

    // --- Lock mismatch ---
    $ctx6 = cpr_mr_fresh_env($base);
    $setup6 = cpr_mr_setup_through_cp11($ctx6['env']);
    $lockMiss = orange_cpr_maint_release_live_run($ctx6['env'], (string) $setup6['job_id'], cpr_mr_req($setup6, [
        'lease_token' => 'wrong-lease-token',
    ]));
    cpr_mr('lock_mismatch', empty($lockMiss['ok']) && ($lockMiss['code'] ?? '') === ORANGE_CPR_MRLIVE_ERR_LOCK);

    // Bypass / auto
    $ctx7 = cpr_mr_fresh_env($base);
    $setup7 = cpr_mr_setup_through_cp11($ctx7['env']);
    $bypass = orange_cpr_maint_release_live_run($ctx7['env'], (string) $setup7['job_id'], cpr_mr_req($setup7, [
        'force_pass' => true,
    ]));
    cpr_mr('privilege_bypass_denied', empty($bypass['ok']) && ($bypass['code'] ?? '') === ORANGE_CPR_MRLIVE_ERR_BYPASS);
    $auto = orange_cpr_maint_release_live_run($ctx7['env'], (string) $setup7['job_id'], cpr_mr_req($setup7, [
        'auto_release' => true,
    ]));
    cpr_mr('auto_release_denied', empty($auto['ok']) && ($auto['code'] ?? '') === ORANGE_CPR_MRLIVE_ERR_AUTO);

    $docs = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup';
    $design = $docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P6_05_MAINT_RELEASE.md';
    $index = (string) file_get_contents($docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P6_ARTIFACT_INDEX.md');
    cpr_mr('design_doc_exists', is_file($design));
    cpr_mr(
        'index_wp_p6_05_complete',
        str_contains($index, 'COUNTRY_PRODUCTION_RESTORE_P6_05_MAINT_RELEASE.md')
        && str_contains($index, '**WP-P6-05 COMPLETE**')
    );
    cpr_mr('design_stop_blocks_p6_06', str_contains(
        (string) file_get_contents($design),
        'Do **not** begin **WP-P6-06**'
    ));
    cpr_mr('ops_enablement_still_false', orange_cpr_enablement_flag_read($env) === false);
} catch (Throwable $e) {
    cpr_mr('exception', false, $e->getMessage());
} finally {
    cpr_mr_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
