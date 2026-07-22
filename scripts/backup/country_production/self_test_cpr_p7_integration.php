<?php

declare(strict_types=1);

/**
 * Self-test: CPR P7 Integration Baseline Freeze (WP-P7-05).
 * Run: php scripts/backup/country_production/self_test_cpr_p7_integration.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p7_integration.php';

$pass = 0;
$fail = 0;

function cpr_p7i(string $name, bool $ok, string $detail = ''): void
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
function cpr_p7i_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p705_' . bin2hex(random_bytes(4));
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

function cpr_p7i_cleanup(string $base): void
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

$bundle = cpr_p7i_env();
$env = $bundle['env'];
$base = $bundle['base'];
$cprRoot = $bundle['cpr'];
$clone = $bundle['clone'];

try {
    cpr_p7i('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P8-02-owner-submission');
    cpr_p7i(
        'stage_order',
        orange_cpr_p7_integration_stage_order() === [
            'clone_harness',
            'environment_binding',
            'ds_scenario_execution',
            'sealed_drill_reports',
            'ev_assembly',
            'sealed_evidence_pack',
        ]
    );
    cpr_p7i(
        'control_plane_freeze_flag',
        !empty(orange_cpr_p7_control_plane_snapshot()['p7_integration_baseline_complete'])
    );
    cpr_p7i(
        'control_plane_no_p8',
        empty(orange_cpr_p7_control_plane_snapshot()['p8_started'])
        && empty(orange_cpr_p7_control_plane_snapshot()['owner_cert_pass_granted'])
    );

    $src = (string) file_get_contents(
        dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p7_integration.php'
    );
    cpr_p7i(
        'no_new_business_sql',
        !preg_match('/\bdb\s*\(|PDO::|mysqli_|DELETE FROM|INSERT INTO/i', $src)
        && str_contains($src, "'production_resources_accessed' => false")
    );
    cpr_p7i(
        'no_enterprise_audit_or_tag_or_p8_in_engine',
        str_contains($src, 'enterprise_audit_started')
        && str_contains($src, 'git_tag_created')
        && str_contains($src, "'p8_started' => false")
    );

    $run = orange_cpr_p7_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'clone_work_root' => $clone,
        'package_fingerprint' => str_repeat('7', 32),
        'schema_revision' => 121,
        'country_id' => 1,
        'country_code' => 'KW',
    ]);
    cpr_p7i(
        'integration_run_ok',
        !empty($run['ok']) && !empty($run['p7_baseline_ready']) && !empty($run['p7_baseline_frozen']),
        (string) ($run['code'] ?? $run['message'] ?? '')
    );
    $jid = (string) ($run['job_id'] ?? '');
    cpr_p7i('job_id_present', $jid !== '');

    $report = orange_cpr_p7_integration_load_latest($cprRoot, $jid);
    cpr_p7i(
        'sealed_freeze_report',
        is_array($report) && !empty($report['p7_baseline_frozen']) && orange_cpr_auth_verify_seal($report)
    );
    cpr_p7i(
        'stage_order_in_report',
        is_array($report) && ($report['stage_order'] ?? null) === orange_cpr_p7_integration_stage_order()
    );
    cpr_p7i(
        'final_artifact_inventory',
        is_array($report)
        && isset($report['final_artifact_inventory']['WP-P7-01'])
        && isset($report['final_artifact_inventory']['WP-P7-05'])
    );

    $verify = orange_cpr_p7_integration_verify($env, $jid, []);
    cpr_p7i('verify_ok', !empty($verify['ok']), (string) ($verify['code'] ?? ''));
    $checkIds = [];
    foreach ($verify['checks'] ?? [] as $c) {
        if (!empty($c['ok'])) {
            $checkIds[(string) ($c['id'] ?? '')] = true;
        }
    }
    foreach ([
        'environment_isolation',
        'scenario_ordering',
        'evidence_ordering',
        'contract_consistency',
        'job_identity_continuity',
        'fingerprint_integrity',
        'audit_chain_continuity',
        'recovery_metadata_integrity',
        'no_orphan_artifacts',
        'no_duplicate_evidence',
        'no_production_resource_access',
    ] as $need) {
        cpr_p7i('verify_' . $need, isset($checkIds[$need]));
    }

    cpr_p7i(
        'no_production_resources',
        ($run['production_resources_accessed'] ?? true) === false
        && ($run['production_sql_executed'] ?? true) === false
        && ($run['enterprise_audit_started'] ?? true) === false
        && ($run['git_tag_created'] ?? true) === false
        && ($run['p8_started'] ?? true) === false
        && ($run['owner_cert_pass_granted'] ?? true) === false
    );

    $again = orange_cpr_p7_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'job_id' => $jid,
        'clone_work_root' => $clone,
        'package_fingerprint' => str_repeat('7', 32),
    ]);
    cpr_p7i(
        'idempotent_freeze',
        !empty($again['ok']) && !empty($again['idempotent']) && !empty($again['exactly_once'])
    );
    $replay = orange_cpr_p7_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'job_id' => $jid,
        'force_replay' => true,
    ]);
    cpr_p7i(
        'no_replay_path',
        empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_P7INT_ERR_REPLAY
    );

    $bypass = orange_cpr_p7_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'force_pass' => true,
        'clone_work_root' => $clone,
    ]);
    cpr_p7i(
        'no_privilege_bypass',
        empty($bypass['ok']) && ($bypass['code'] ?? '') === ORANGE_CPR_P7INT_ERR_BYPASS
    );
    $p8 = orange_cpr_p7_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'begin_p8' => true,
        'clone_work_root' => $clone,
    ]);
    cpr_p7i(
        'no_p8_start',
        empty($p8['ok']) && ($p8['code'] ?? '') === ORANGE_CPR_P7INT_ERR_P8
    );
    $auditGate = orange_cpr_p7_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'begin_enterprise_audit' => true,
        'clone_work_root' => $clone,
    ]);
    cpr_p7i(
        'no_enterprise_audit',
        empty($auditGate['ok']) && ($auditGate['code'] ?? '') === ORANGE_CPR_P7INT_ERR_P8
    );

    $docs = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup';
    $baseline = $docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P7_05_INTEGRATION_BASELINE.md';
    $index = $docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P7_ARTIFACT_INDEX.md';
    cpr_p7i('baseline_doc_exists', is_file($baseline));
    $indexText = is_file($index) ? (string) file_get_contents($index) : '';
    cpr_p7i('index_wp_p7_05_complete', str_contains($indexText, '**WP-P7-05 COMPLETE**'));
    cpr_p7i(
        'index_enterprise_audit_passed_blocks_tag_p8',
        str_contains($indexText, 'P7 ENTERPRISE AUDIT COMPLETE')
        && str_contains($indexText, 'PASSED')
        && str_contains($indexText, 'Do **not** begin **P8**')
        && str_contains($indexText, 'Git Tag')
    );

    $assert = orange_cpr_p7_control_plane_assert(['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false]);
    cpr_p7i('control_plane_assert_ok_after_freeze', !empty($assert['ok']), (string) ($assert['code'] ?? ''));
} catch (Throwable $e) {
    cpr_p7i('exception', false, $e->getMessage());
} finally {
    cpr_p7i_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
