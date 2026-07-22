<?php

declare(strict_types=1);

/**
 * Self-test: CPR P8 Integration Baseline Freeze (WP-P8-04).
 * Run: php scripts/backup/country_production/self_test_cpr_p8_integration.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p8_integration.php';

$pass = 0;
$fail = 0;

function cpr_p8i(string $name, bool $ok, string $detail = ''): void
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
function cpr_p8i_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p804_' . bin2hex(random_bytes(4));
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

function cpr_p8i_cleanup(string $base): void
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

$bundle = cpr_p8i_env();
$env = $bundle['env'];
$base = $bundle['base'];
$clone = $bundle['clone'];

try {
    cpr_p8i('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P8-04-integration-baseline');
    cpr_p8i(
        'stage_order',
        orange_cpr_p8_integration_stage_order() === [
            'sealed_owner_submission',
            'owner_certification_ceremony',
            'pass_or_fail_decision',
            'sealed_certification_result',
            'integration_freeze',
        ]
    );
    cpr_p8i(
        'control_plane_freeze_flag',
        !empty(orange_cpr_p8_control_plane_snapshot()['p8_integration_baseline_complete'])
    );
    cpr_p8i(
        'control_plane_no_p9_audit_tag',
        empty(orange_cpr_p8_control_plane_snapshot()['p9_started'])
        && empty(orange_cpr_p8_control_plane_snapshot()['enterprise_audit_started'])
        && empty(orange_cpr_p8_control_plane_snapshot()['git_tag_created'])
        && empty(orange_cpr_p8_control_plane_snapshot()['owner_cert_pass_granted'])
    );

    $src = (string) file_get_contents(
        dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p8_integration.php'
    );
    cpr_p8i(
        'no_new_business_sql',
        !preg_match('/\bdb\s*\(|PDO::|mysqli_|DELETE FROM|INSERT INTO/i', $src)
        && str_contains($src, "'production_resources_accessed' => false")
    );
    cpr_p8i(
        'no_enterprise_audit_or_tag_or_p9_in_engine',
        str_contains($src, 'enterprise_audit_started')
        && str_contains($src, 'git_tag_created')
        && str_contains($src, "'p9_started' => false")
        && str_contains($src, 'ORANGE_CPR_P8INT_ERR_P9')
    );
    cpr_p8i(
        'proof_pass_does_not_enable_in_source',
        str_contains($src, "'cert_pass_does_not_enable' => true")
        && str_contains($src, "'enablement_flag_after_decision' => false")
    );
    cpr_p8i(
        'proof_fail_no_auto_rollback_in_source',
        str_contains($src, "'fail_does_not_auto_rollback' => true")
        && str_contains($src, "'auto_rollback_triggered' => false")
    );

    $run = orange_cpr_p8_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'owner_actor_id' => 42,
        'clone_work_root' => $clone,
        'package_fingerprint' => str_repeat('8', 32),
        'schema_revision' => 121,
        'country_id' => 1,
        'country_code' => 'KW',
        'cert_result' => 'PASS',
    ]);
    cpr_p8i('valid_pass_chain_freeze', !empty($run['ok']) && !empty($run['p8_baseline_frozen']), (string) ($run['code'] ?? ''));
    cpr_p8i('result_pass', ($run['result'] ?? '') === 'PASS');
    cpr_p8i('lifecycle_cert_pass', ($run['lifecycle_state'] ?? '') === 'cert_pass');
    cpr_p8i(
        'proof_pass_does_not_enable_production',
        ($run['enablement_flag_after_decision'] ?? true) === false
        && ($run['enablement_flag_observed'] ?? true) === false
        && orange_cpr_enablement_flag_read($env) === false
    );
    $report = is_array($run['integration_report'] ?? null) ? $run['integration_report'] : null;
    cpr_p8i(
        'sealed_freeze_report',
        is_array($report) && orange_cpr_auth_verify_seal($report) && !empty($report['p8_baseline_frozen'])
    );
    cpr_p8i(
        'stage_order_executed',
        is_array($run['stage_order'] ?? null)
        && $run['stage_order'] === orange_cpr_p8_integration_stage_order()
    );
    $verify = is_array($run['verification'] ?? null) ? $run['verification'] : [];
    cpr_p8i('verification_ok', !empty($verify['ok']));
    $checks = is_array($verify['checks'] ?? null) ? $verify['checks'] : [];
    $checkIds = [];
    foreach ($checks as $c) {
        if (is_array($c) && !empty($c['ok'])) {
            $checkIds[] = (string) ($c['id'] ?? '');
        }
    }
    foreach ([
        'submission_integrity',
        'certification_integrity',
        'pass_fail_exclusivity',
        'contract_consistency',
        'job_identity_continuity',
        'fingerprint_integrity',
        'audit_chain_continuity',
        'recovery_metadata_integrity',
        'no_orphan_artifacts',
        'no_duplicate_certification',
        'pass_does_not_enable',
        'fail_does_not_auto_rollback',
    ] as $need) {
        cpr_p8i('verify_' . $need, in_array($need, $checkIds, true));
    }

    $idem = orange_cpr_p8_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'owner_actor_id' => 42,
        'job_id' => (string) ($run['job_id'] ?? ''),
        'clone_work_root' => $clone,
        'package_fingerprint' => str_repeat('8', 32),
        'cert_result' => 'PASS',
    ]);
    cpr_p8i('idempotent_refreeze', !empty($idem['ok']) && !empty($idem['idempotent']));

    $replay = orange_cpr_p8_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'owner_actor_id' => 42,
        'job_id' => (string) ($run['job_id'] ?? ''),
        'force_replay' => true,
        'clone_work_root' => $clone,
        'package_fingerprint' => str_repeat('8', 32),
    ]);
    cpr_p8i(
        'replay_refused',
        empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_P8INT_ERR_REPLAY
    );

    $p9 = orange_cpr_p8_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'begin_p9' => true,
        'clone_work_root' => $clone,
    ]);
    cpr_p8i('p9_forbidden', empty($p9['ok']) && ($p9['code'] ?? '') === ORANGE_CPR_P8INT_ERR_P9);

    $auditKnob = orange_cpr_p8_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'begin_enterprise_audit' => true,
        'clone_work_root' => $clone,
    ]);
    cpr_p8i(
        'enterprise_audit_forbidden',
        empty($auditKnob['ok']) && ($auditKnob['code'] ?? '') === ORANGE_CPR_P8INT_ERR_P9
    );

    // FAIL path freeze
    $bundleF = cpr_p8i_env();
    $runFail = orange_cpr_p8_integration_run($bundleF['env'], [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'owner_actor_id' => 42,
        'clone_work_root' => $bundleF['clone'],
        'package_fingerprint' => str_repeat('a', 32),
        'schema_revision' => 121,
        'country_id' => 1,
        'country_code' => 'KW',
        'cert_result' => 'FAIL',
    ]);
    cpr_p8i('valid_fail_chain_freeze', !empty($runFail['ok']) && ($runFail['result'] ?? '') === 'FAIL', (string) ($runFail['code'] ?? ''));
    cpr_p8i(
        'proof_fail_does_not_trigger_automatic_rollback',
        ($runFail['auto_rollback_triggered'] ?? true) === false
        && ($runFail['fail_does_not_auto_rollback'] ?? false) === true
    );
    cpr_p8i_cleanup($bundleF['base']);

    $docs = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup';
    cpr_p8i(
        'design_doc_exists',
        is_file($docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P8_04_INTEGRATION_BASELINE.md')
    );
    $index = (string) file_get_contents($docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P8_ARTIFACT_INDEX.md');
    cpr_p8i('index_wp_p8_04_complete', str_contains($index, '**WP-P8-04 COMPLETE**'));
    cpr_p8i('index_stop_blocks_enterprise_audit', str_contains($index, 'Do **not** start the Enterprise Audit'));
    cpr_p8i('index_stop_blocks_git_tag', str_contains($index, 'Do **not** create the Git Tag'));
    cpr_p8i('index_stop_blocks_p9', str_contains($index, 'Do **not** begin **P9**'));

    $assert = orange_cpr_p8_control_plane_assert(['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false]);
    cpr_p8i('control_plane_assert_ok', !empty($assert['ok']), (string) ($assert['code'] ?? ''));

    cpr_p8i(
        'no_enterprise_audit_started_flag',
        ($run['enterprise_audit_started'] ?? true) === false
        && ($run['git_tag_created'] ?? true) === false
        && ($run['p9_started'] ?? true) === false
    );
} catch (Throwable $e) {
    cpr_p8i('exception', false, $e->getMessage());
} finally {
    cpr_p8i_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
