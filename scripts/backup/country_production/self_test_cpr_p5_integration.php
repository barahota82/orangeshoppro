<?php

declare(strict_types=1);

/**
 * Self-test: CPR P5 Integration Baseline Freeze (WP-P5-06).
 * Run: php scripts/backup/country_production/self_test_cpr_p5_integration.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p5_integration.php';

$pass = 0;
$fail = 0;

function cpr_p5i(string $name, bool $ok, string $detail = ''): void
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
function cpr_p5i_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p506_' . bin2hex(random_bytes(4));
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

function cpr_p5i_cleanup(string $base): void
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

$bundle = cpr_p5i_env();
$env = $bundle['env'];
$base = $bundle['base'];
$cprRoot = $bundle['cpr'];

try {
    cpr_p5i('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P6-02-post-verify');
    cpr_p5i(
        'stage_order_complete',
        orange_cpr_p5_integration_stage_order() === [
            'p4_pre_ponr_through_cpa',
            'target_slice_delete',
            'cp6',
            'target_slice_import_batches_1_6',
            'cp7',
            'special_handlers',
            'cp8',
            'country_uploads_apply',
            'cp9',
        ]
    );

    $src = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p5_integration.php');
    cpr_p5i(
        'no_new_business_sql',
        !preg_match('/\bdb\s*\(|PDO::|mysqli_|DELETE FROM|INSERT INTO/i', $src)
    );

    $run = orange_cpr_p5_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'worker_id' => 'p5i-worker',
    ]);
    cpr_p5i(
        'integration_run_ok',
        !empty($run['ok']) && !empty($run['p5_baseline_ready']),
        (string) ($run['code'] ?? $run['message'] ?? '')
    );
    $jid = (string) ($run['job_id'] ?? '');
    cpr_p5i('job_id_present', $jid !== '');

    $report = orange_cpr_p5_integration_load_latest($cprRoot, $jid);
    cpr_p5i('sealed_integration_report', is_array($report) && orange_cpr_auth_verify_seal($report));
    cpr_p5i('report_baseline_ready', !empty($report['p5_baseline_ready']));
    cpr_p5i('report_no_enterprise_audit', empty($report['enterprise_audit_started']));
    cpr_p5i('report_no_git_tag', empty($report['git_tag_created']));
    cpr_p5i('report_no_p6', empty($report['p6_started']));
    cpr_p5i('report_no_production_sql', ($report['production_sql_executed'] ?? true) === false);
    cpr_p5i('report_no_upload_mutation', ($report['production_uploads_mutated'] ?? true) === false);

    $stages = is_array($run['stages'] ?? null) ? $run['stages'] : [];
    $stageNames = [];
    foreach ($stages as $s) {
        if (is_array($s) && !empty($s['ok'])) {
            $stageNames[] = (string) ($s['stage'] ?? '');
        }
    }
    foreach ([
        'p4_pre_ponr_through_cpa',
        'target_slice_delete',
        'cp6',
        'target_slice_import_batches_1_6',
        'cp7',
        'special_handlers',
        'cp8',
        'country_uploads_apply',
        'cp9',
    ] as $need) {
        cpr_p5i('stage_' . $need, in_array($need, $stageNames, true));
    }

    cpr_p5i('verify_checks_present', (int) (($run['verification']['check_count'] ?? 0)) >= 25);
    cpr_p5i('enablement_false', ($run['enablement_flag_observed'] ?? null) === false);
    cpr_p5i('ponr_crossed', !empty($run['ponr_crossed']));
    cpr_p5i('cp9_exists', $jid !== '' && orange_cpr_checkpoint_exists($cprRoot, $jid, 'CP9'));
    cpr_p5i('cp10_absent', $jid === '' || !orange_cpr_checkpoint_exists($cprRoot, $jid, 'CP10'));

    $snap = orange_cpr_p5_control_plane_snapshot();
    cpr_p5i('control_plane_baseline_ready', !empty($snap['p5_baseline_ready']));
    cpr_p5i('control_plane_no_p6', empty($snap['p6_started']));

    $denied = orange_cpr_p5_integration_run($env, [
        'actor_admin_id' => 99,
        'actor_is_super_admin' => false,
    ]);
    cpr_p5i(
        'deny_non_super_admin',
        empty($denied['ok']) && ($denied['code'] ?? '') === ORANGE_CPR_P5INT_ERR_ACTOR,
        (string) ($denied['code'] ?? '')
    );

    $bypass = orange_cpr_p5_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'force_pass' => true,
    ]);
    cpr_p5i(
        'deny_bypass',
        empty($bypass['ok']) && ($bypass['code'] ?? '') === ORANGE_CPR_P5INT_ERR_BYPASS,
        (string) ($bypass['code'] ?? '')
    );

    $p6 = orange_cpr_p5_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'begin_p6' => true,
    ]);
    cpr_p5i(
        'deny_begin_p6',
        empty($p6['ok']) && ($p6['code'] ?? '') === ORANGE_CPR_P5INT_ERR_P6,
        (string) ($p6['code'] ?? '')
    );

    $docsRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup';
    $baselineDoc = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P5_06_INTEGRATION_BASELINE.md';
    $indexPath = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P5_ARTIFACT_INDEX.md';
    cpr_p5i('baseline_doc_exists', is_file($baselineDoc));
    $index = is_file($indexPath) ? (string) file_get_contents($indexPath) : '';
    cpr_p5i(
        'index_wp_p5_06_complete',
        str_contains($index, '**WP-P5-06**') && str_contains($index, '**WP-P5-06 COMPLETE**')
    );
    cpr_p5i('index_stop_blocks_p6', str_contains($index, 'Do **not** begin **P6**'));

    cpr_p5i('ops_enablement_still_false', orange_cpr_enablement_flag_read($env) === false);
} catch (Throwable $e) {
    cpr_p5i('exception', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . (string) $e->getLine());
} finally {
    cpr_p5i_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
