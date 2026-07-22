<?php

declare(strict_types=1);

/**
 * Self-test: CPR P9 Integration Baseline Freeze (WP-P9-04).
 * Run: php scripts/backup/country_production/self_test_cpr_p9_integration.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p9_integration.php';

$pass = 0;
$fail = 0;

function cpr_p9i(string $name, bool $ok, string $detail = ''): void
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
 * @return array{env:array<string,mixed>,cpr:string,base:string,clone:string}
 */
function cpr_p9i_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p904_' . bin2hex(random_bytes(4));
    $restoreWork = $base . DIRECTORY_SEPARATOR . 'restore_work';
    $cpr = $restoreWork . DIRECTORY_SEPARATOR . 'country_production';
    $clone = $base . DIRECTORY_SEPARATOR . 'clone';
    $backupRoot = $base . DIRECTORY_SEPARATOR . 'backup_root';
    @mkdir($cpr, 0775, true);
    @mkdir($clone, 0775, true);
    @mkdir($backupRoot . DIRECTORY_SEPARATOR . 'locks', 0775, true);

    return [
        'base' => $base,
        'cpr' => $cpr,
        'clone' => $clone,
        'env' => [
            'ORANGE_CPR_WORK_DIR' => $cpr,
            'ORANGE_RESTORE_WORK_DIR' => $restoreWork,
            'ORANGE_BACKUP_ROOT' => $backupRoot,
            'ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false,
        ],
    ];
}

function cpr_p9i_cleanup(string $base): void
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

$bundle = cpr_p9i_env();
$env = $bundle['env'];
$base = $bundle['base'];
$clone = $bundle['clone'];

try {
    cpr_p9i('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P9-04-integration-baseline');
    cpr_p9i(
        'stage_order',
        orange_cpr_p9_integration_stage_order() === [
            'owner_certification',
            'e5_preconditions',
            'super_admin_enable',
            'operational_enablement',
            'operational_disable',
            'schema_force_disable',
            'integration_freeze',
        ]
    );
    cpr_p9i(
        'control_plane_freeze_flag',
        !empty(orange_cpr_p9_control_plane_snapshot()['p9_integration_baseline_complete'])
    );
    cpr_p9i(
        'control_plane_no_audit_tag_signoff',
        empty(orange_cpr_p9_control_plane_snapshot()['enterprise_audit_started'])
        && empty(orange_cpr_p9_control_plane_snapshot()['git_tag_created'])
        && empty(orange_cpr_p9_control_plane_snapshot()['phase_sign_off_started'])
        && empty(orange_cpr_p9_control_plane_snapshot()['project_closed'])
    );

    $src = (string) file_get_contents(
        dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p9_integration.php'
    );
    cpr_p9i(
        'no_new_business_sql',
        !preg_match('/\bdb\s*\(|PDO::|mysqli_|DELETE FROM|INSERT INTO/i', $src)
        && str_contains($src, "'production_resources_accessed' => false")
    );
    cpr_p9i(
        'no_enterprise_audit_or_tag_in_engine',
        str_contains($src, 'enterprise_audit_started')
        && str_contains($src, 'git_tag_created')
        && str_contains($src, "'project_closed' => false")
        && str_contains($src, 'ORANGE_CPR_P9INT_ERR_CLOSURE')
    );
    cpr_p9i(
        'proof_only_p9_03_writes_flag',
        str_contains($src, "'only_wp_p9_03_may_change_flag' => true")
        && !str_contains($src, 'orange_cpr_enablement_ops_state_write')
    );

    $run = orange_cpr_p9_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'owner_actor_id' => 42,
        'clone_work_root' => $clone,
        'package_fingerprint' => str_repeat('9', 32),
        'schema_revision' => 121,
        'country_id' => 1,
        'country_code' => 'KW',
    ]);
    cpr_p9i('valid_chain_freeze', !empty($run['ok']) && !empty($run['p9_baseline_frozen']), (string) ($run['code'] ?? ''));
    cpr_p9i('final_state_e8', ($run['enablement_state_final'] ?? '') === ORANGE_CPR_EACT_STATE_E8);
    cpr_p9i(
        'final_flag_false',
        ($run['ops_flag_final'] ?? true) === false
        && orange_cpr_enablement_flag_read($env) === false
    );
    $report = is_array($run['integration_report'] ?? null) ? $run['integration_report'] : null;
    cpr_p9i(
        'sealed_freeze_report',
        is_array($report) && orange_cpr_auth_verify_seal($report) && !empty($report['p9_baseline_frozen'])
    );
    cpr_p9i(
        'stage_order_executed',
        is_array($run['stage_order'] ?? null)
        && $run['stage_order'] === orange_cpr_p9_integration_stage_order()
    );
    $verify = is_array($run['verification'] ?? null) ? $run['verification'] : [];
    cpr_p9i('verification_ok', !empty($verify['ok']));
    $checks = is_array($verify['checks'] ?? null) ? $verify['checks'] : [];
    $checkIds = [];
    foreach ($checks as $c) {
        if (is_array($c) && !empty($c['ok'])) {
            $checkIds[] = (string) ($c['id'] ?? '');
        }
    }
    foreach ([
        'enablement_prerequisite_integrity',
        'enable_integrity',
        'disable_integrity',
        'schema_invalidation_integrity',
        'contract_consistency',
        'job_identity_continuity',
        'permission_integrity',
        'fingerprint_integrity',
        'audit_chain_continuity',
        'recovery_metadata_integrity',
        'no_orphan_artifacts',
        'enablement_flag_final_state',
        'state_engine_integrated',
        'checkpoint_engine_integrated',
    ] as $need) {
        cpr_p9i('verify_' . $need, in_array($need, $checkIds, true));
    }

    $idem = orange_cpr_p9_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'owner_actor_id' => 42,
        'job_id' => (string) ($run['job_id'] ?? ''),
        'clone_work_root' => $clone,
        'package_fingerprint' => str_repeat('9', 32),
    ]);
    cpr_p9i('idempotent_refreeze', !empty($idem['ok']) && !empty($idem['idempotent']));

    $replay = orange_cpr_p9_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'owner_actor_id' => 42,
        'job_id' => (string) ($run['job_id'] ?? ''),
        'force_replay' => true,
        'clone_work_root' => $clone,
        'package_fingerprint' => str_repeat('9', 32),
    ]);
    cpr_p9i(
        'replay_refused',
        empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_P9INT_ERR_REPLAY
    );

    $auditKnob = orange_cpr_p9_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'begin_enterprise_audit' => true,
        'clone_work_root' => $clone,
    ]);
    cpr_p9i(
        'enterprise_audit_forbidden',
        empty($auditKnob['ok']) && ($auditKnob['code'] ?? '') === ORANGE_CPR_P9INT_ERR_CLOSURE
    );

    $tagKnob = orange_cpr_p9_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'create_git_tag' => true,
        'clone_work_root' => $clone,
    ]);
    cpr_p9i(
        'git_tag_forbidden',
        empty($tagKnob['ok']) && ($tagKnob['code'] ?? '') === ORANGE_CPR_P9INT_ERR_CLOSURE
    );

    $perm = orange_cpr_p9_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => false,
        'clone_work_root' => $clone,
    ]);
    cpr_p9i(
        'permission_failure',
        empty($perm['ok']) && ($perm['code'] ?? '') === ORANGE_CPR_P9INT_ERR_ACTOR
    );

    $docs = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup';
    cpr_p9i(
        'design_doc_exists',
        is_file($docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P9_04_INTEGRATION_BASELINE.md')
    );
    $index = (string) file_get_contents($docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P9_ARTIFACT_INDEX.md');
    cpr_p9i('index_wp_p9_04_complete', str_contains($index, '**WP-P9-04 COMPLETE**'));
    cpr_p9i('index_stop_blocks_enterprise_audit', str_contains($index, 'Do **not** start the Enterprise Audit'));
    cpr_p9i('index_stop_blocks_git_tag', str_contains($index, 'Do **not** create the Git Tag'));
    cpr_p9i('index_stop_blocks_project_complete', str_contains($index, 'Do **not** declare the project complete'));

    $assert = orange_cpr_p9_control_plane_assert(['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false]);
    cpr_p9i('control_plane_assert_ok', !empty($assert['ok']), (string) ($assert['code'] ?? ''));

    cpr_p9i(
        'no_closure_flags',
        ($run['enterprise_audit_started'] ?? true) === false
        && ($run['git_tag_created'] ?? true) === false
        && ($run['phase_sign_off_started'] ?? true) === false
        && ($run['project_closed'] ?? true) === false
    );
} catch (Throwable $e) {
    cpr_p9i('exception', false, $e->getMessage());
} finally {
    cpr_p9i_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
