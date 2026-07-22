<?php

declare(strict_types=1);

/**
 * Self-test: CPR P6 Integration Baseline Freeze (WP-P6-06).
 * Run: php scripts/backup/country_production/self_test_cpr_p6_integration.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p6_integration.php';

$pass = 0;
$fail = 0;

function cpr_p6i(string $name, bool $ok, string $detail = ''): void
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
 * @return array{env:array<string,mixed>,cpr:string,base:string}
 */
function cpr_p6i_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p606_' . bin2hex(random_bytes(4));
    $restoreWork = $base . DIRECTORY_SEPARATOR . 'restore_work';
    $cpr = $restoreWork . DIRECTORY_SEPARATOR . 'country_production';
    $shadow = $restoreWork . DIRECTORY_SEPARATOR . 'country_shadow';
    $backupRoot = $base . DIRECTORY_SEPARATOR . 'backup_root';
    @mkdir($cpr, 0775, true);
    @mkdir($shadow, 0775, true);
    @mkdir($backupRoot . DIRECTORY_SEPARATOR . 'locks', 0775, true);

    return [
        'base' => $base,
        'cpr' => $cpr,
        'env' => [
            'ORANGE_CPR_WORK_DIR' => $cpr,
            'ORANGE_RESTORE_WORK_DIR' => $restoreWork,
            'ORANGE_BACKUP_ROOT' => $backupRoot,
            'ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false,
        ],
    ];
}

function cpr_p6i_cleanup(string $base): void
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

$bundle = cpr_p6i_env();
$env = $bundle['env'];
$base = $bundle['base'];
$cprRoot = $bundle['cpr'];

try {
    cpr_p6i('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P6-06-integration-baseline');
    cpr_p6i(
        'stage_order_success',
        orange_cpr_p6_integration_stage_order_success() === [
            'p5_through_cp9',
            'post_verify',
            'cp10',
            'success_finalize',
            'cp11',
            'maintenance_release',
            'cp12',
        ]
    );
    cpr_p6i(
        'stage_order_rollback',
        orange_cpr_p6_integration_stage_order_rollback() === [
            'p5_through_cp9',
            'post_verify_fail_pause',
            'od_rollback',
            'rollback_completed',
            'maintenance_release',
            'cp12',
        ]
    );

    $src = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p6_integration.php');
    cpr_p6i(
        'no_new_business_sql',
        !preg_match('/\bdb\s*\(|PDO::|mysqli_|DELETE FROM|INSERT INTO/i', $src)
    );

    // --- Success path ---
    $run = orange_cpr_p6_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'worker_id' => 'p6i-worker',
        'path' => 'success',
    ]);
    cpr_p6i(
        'success_run_ok',
        !empty($run['ok']) && !empty($run['p6_baseline_ready']),
        (string) ($run['code'] ?? $run['message'] ?? '')
    );
    $jid = (string) ($run['job_id'] ?? '');
    cpr_p6i('success_job_id_present', $jid !== '');

    $report = orange_cpr_p6_integration_load_latest($cprRoot, $jid);
    cpr_p6i('success_sealed_report', is_array($report) && orange_cpr_auth_verify_seal($report));
    cpr_p6i('success_report_baseline_ready', !empty($report['p6_baseline_ready']));
    cpr_p6i('success_report_no_enterprise_audit', empty($report['enterprise_audit_started']));
    cpr_p6i('success_report_no_git_tag', empty($report['git_tag_created']));
    cpr_p6i('success_report_no_p7', empty($report['p7_started']));
    cpr_p6i('success_report_no_production_sql', ($report['production_sql_executed'] ?? true) === false);
    cpr_p6i('success_report_no_upload_mutation', ($report['production_uploads_mutated'] ?? true) === false);

    $stages = is_array($run['stages'] ?? null) ? $run['stages'] : [];
    $stageNames = [];
    foreach ($stages as $s) {
        if (is_array($s) && !empty($s['ok'])) {
            $stageNames[] = (string) ($s['stage'] ?? '');
        }
    }
    foreach ([
        'p5_through_cp9',
        'post_verify',
        'cp10',
        'success_finalize',
        'cp11',
        'maintenance_release',
        'cp12',
    ] as $need) {
        cpr_p6i('success_stage_' . $need, in_array($need, $stageNames, true));
    }

    cpr_p6i(
        'success_verify_checks_present',
        (int) (($report['verification']['check_count'] ?? 0)) >= 20
    );
    cpr_p6i('success_enablement_false', ($run['enablement_flag_observed'] ?? null) === false);
    cpr_p6i('success_ponr_crossed', !empty($run['ponr_crossed']));
    cpr_p6i('success_cp10_exists', $jid !== '' && orange_cpr_checkpoint_exists($cprRoot, $jid, 'CP10'));
    cpr_p6i('success_cp11_exists', $jid !== '' && orange_cpr_checkpoint_exists($cprRoot, $jid, 'CP11'));
    cpr_p6i('success_cp12_exists', $jid !== '' && orange_cpr_checkpoint_exists($cprRoot, $jid, 'CP12'));
    cpr_p6i(
        'success_state_released',
        $jid !== '' && (string) (orange_cpr_job_read($cprRoot, $jid)['state'] ?? '') === 'cpr_maintenance_released'
    );

    // --- Rollback path (fresh env) ---
    $bundleRb = cpr_p6i_env();
    $envRb = $bundleRb['env'];
    $baseRb = $bundleRb['base'];
    $cprRb = $bundleRb['cpr'];
    $runRb = orange_cpr_p6_integration_run($envRb, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'worker_id' => 'p6i-rb-worker',
        'path' => 'rollback',
    ]);
    cpr_p6i(
        'rollback_run_ok',
        !empty($runRb['ok']) && !empty($runRb['p6_baseline_ready']),
        (string) ($runRb['code'] ?? $runRb['message'] ?? '')
    );
    $jidRb = (string) ($runRb['job_id'] ?? '');
    cpr_p6i('rollback_job_id_present', $jidRb !== '');
    cpr_p6i('rollback_cp12_exists', $jidRb !== '' && orange_cpr_checkpoint_exists($cprRb, $jidRb, 'CP12'));
    cpr_p6i('rollback_cp10_absent', $jidRb === '' || !orange_cpr_checkpoint_exists($cprRb, $jidRb, 'CP10'));
    cpr_p6i('rollback_cp11_absent', $jidRb === '' || !orange_cpr_checkpoint_exists($cprRb, $jidRb, 'CP11'));
    $rbReport = $jidRb !== '' ? orange_cpr_rollback_live_load_latest($cprRb, $jidRb, 'report') : null;
    cpr_p6i(
        'rollback_sealed_complete',
        is_array($rbReport) && !empty($rbReport['rollback_completed']) && orange_cpr_auth_verify_seal($rbReport)
    );
    cpr_p6i(
        'rollback_state_released',
        $jidRb !== '' && (string) (orange_cpr_job_read($cprRb, $jidRb)['state'] ?? '') === 'cpr_maintenance_released'
    );
    $stagesRb = is_array($runRb['stages'] ?? null) ? $runRb['stages'] : [];
    $stageNamesRb = [];
    foreach ($stagesRb as $s) {
        if (is_array($s) && !empty($s['ok'])) {
            $stageNamesRb[] = (string) ($s['stage'] ?? '');
        }
    }
    foreach ([
        'p5_through_cp9',
        'post_verify_fail_pause',
        'od_rollback',
        'rollback_completed',
        'maintenance_release',
        'cp12',
    ] as $need) {
        cpr_p6i('rollback_stage_' . $need, in_array($need, $stageNamesRb, true));
    }
    cpr_p6i_cleanup($baseRb);

    $snap = orange_cpr_p6_control_plane_snapshot();
    cpr_p6i('control_plane_baseline_complete', !empty($snap['p6_integration_baseline_complete']));
    cpr_p6i('control_plane_no_p7', empty($snap['p7_started']));

    $denied = orange_cpr_p6_integration_run($env, [
        'actor_admin_id' => 99,
        'actor_is_super_admin' => false,
    ]);
    cpr_p6i(
        'deny_non_super_admin',
        empty($denied['ok']) && ($denied['code'] ?? '') === ORANGE_CPR_P6INT_ERR_ACTOR,
        (string) ($denied['code'] ?? '')
    );

    $bypass = orange_cpr_p6_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'force_pass' => true,
    ]);
    cpr_p6i(
        'deny_bypass',
        empty($bypass['ok']) && ($bypass['code'] ?? '') === ORANGE_CPR_P6INT_ERR_BYPASS,
        (string) ($bypass['code'] ?? '')
    );

    $p7 = orange_cpr_p6_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'begin_p7' => true,
    ]);
    cpr_p6i(
        'deny_begin_p7',
        empty($p7['ok']) && ($p7['code'] ?? '') === ORANGE_CPR_P6INT_ERR_P7,
        (string) ($p7['code'] ?? '')
    );

    $audit = orange_cpr_p6_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'begin_enterprise_audit' => true,
    ]);
    cpr_p6i(
        'deny_enterprise_audit',
        empty($audit['ok']) && ($audit['code'] ?? '') === ORANGE_CPR_P6INT_ERR_P7,
        (string) ($audit['code'] ?? '')
    );

    $tag = orange_cpr_p6_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'create_git_tag' => true,
    ]);
    cpr_p6i(
        'deny_git_tag',
        empty($tag['ok']) && ($tag['code'] ?? '') === ORANGE_CPR_P6INT_ERR_P7,
        (string) ($tag['code'] ?? '')
    );

    $docsRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup';
    $baselineDoc = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P6_06_INTEGRATION_BASELINE.md';
    $indexPath = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P6_ARTIFACT_INDEX.md';
    cpr_p6i('baseline_doc_exists', is_file($baselineDoc));
    $index = is_file($indexPath) ? (string) file_get_contents($indexPath) : '';
    cpr_p6i(
        'index_wp_p6_06_complete',
        str_contains($index, '**WP-P6-06**') && str_contains($index, '**WP-P6-06 COMPLETE**')
    );
    cpr_p6i('index_stop_blocks_enterprise_audit', str_contains($index, 'Do **not** start **Enterprise Audit**'));
    cpr_p6i('index_stop_blocks_git_tag', str_contains($index, 'Do **not** create a **Git Tag**'));
    cpr_p6i('index_stop_blocks_p7', str_contains($index, 'Do **not** begin **P7**'));

    cpr_p6i('ops_enablement_still_false', orange_cpr_enablement_flag_read($env) === false);
} catch (Throwable $e) {
    cpr_p6i('exception', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . (string) $e->getLine());
} finally {
    cpr_p6i_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
