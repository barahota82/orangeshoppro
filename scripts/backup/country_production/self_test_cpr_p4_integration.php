<?php

declare(strict_types=1);

/**
 * Self-test: CPR P4 Integration Baseline Freeze (WP-P4-09).
 * Run: php scripts/backup/country_production/self_test_cpr_p4_integration.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p4_integration.php';

$pass = 0;
$fail = 0;

function cpr_p4i(string $name, bool $ok, string $detail = ''): void
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
 * @return array{env:array<string,mixed>,cpr:string,base:string}
 */
function cpr_p4i_env(): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p409_' . bin2hex(random_bytes(4));
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

function cpr_p4i_cleanup(string $base): void
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

$bundle = cpr_p4i_env();
$env = $bundle['env'];
$base = $bundle['base'];
$cprRoot = $bundle['cpr'];

try {
    cpr_p4i('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P7-04-evidence-pack');
    cpr_p4i(
        'stage_order_complete',
        orange_cpr_p4_integration_stage_order() === [
            'cp4_maint',
            'session_full_backup',
            'verify_backup',
            'cp1_pin',
            'lock_acquire',
            'gates_live',
            'authority_ceremony',
            'witnesses_capture',
            'cp5',
            'cpa',
        ]
    );

    $run = orange_cpr_p4_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'worker_id' => 'p4i-worker',
    ]);
    cpr_p4i('integration_run_ok', !empty($run['ok']) && !empty($run['p4_baseline_ready']), (string) ($run['code'] ?? $run['message'] ?? ''));
    $jid = (string) ($run['job_id'] ?? '');
    cpr_p4i('job_id_present', $jid !== '');

    $report = orange_cpr_p4_integration_load_latest($cprRoot, $jid);
    cpr_p4i('sealed_integration_report', is_array($report) && orange_cpr_auth_verify_seal($report));
    cpr_p4i('report_baseline_ready', !empty($report['p4_baseline_ready']));
    cpr_p4i('report_no_enterprise_audit', empty($report['enterprise_audit_started']));
    cpr_p4i('report_no_git_tag', empty($report['git_tag_created']));
    cpr_p4i('report_no_p5', empty($report['p5_started']));

    $stages = is_array($run['stages'] ?? null) ? $run['stages'] : [];
    $stageNames = [];
    foreach ($stages as $s) {
        if (is_array($s) && !empty($s['ok'])) {
            $stageNames[] = (string) ($s['stage'] ?? '');
        }
    }
    foreach (['cp4_maint', 'cp1_pin', 'lock_acquire', 'gates_live', 'authority_ceremony', 'cp5', 'cpa'] as $need) {
        cpr_p4i('stage_' . $need, in_array($need, $stageNames, true));
    }

    cpr_p4i('verify_checks_present', (int) (($run['verification']['check_count'] ?? 0)) >= 20);
    cpr_p4i('enablement_false', ($run['enablement_flag_observed'] ?? null) === false);
    cpr_p4i('no_ponr', empty($run['ponr_crossed']) && empty($run['ponr_mutation_executed']));

    // Fail-closed: non-SA
    $denied = orange_cpr_p4_integration_run($env, [
        'actor_admin_id' => 99,
        'actor_is_super_admin' => false,
    ]);
    cpr_p4i(
        'deny_non_super_admin',
        empty($denied['ok']) && ($denied['code'] ?? '') === ORANGE_CPR_P4INT_ERR_ACTOR,
        (string) ($denied['code'] ?? '')
    );

    // Bypass forbidden
    $bypass = orange_cpr_p4_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'force_pass' => true,
    ]);
    cpr_p4i(
        'deny_bypass',
        empty($bypass['ok']) && ($bypass['code'] ?? '') === ORANGE_CPR_P4INT_ERR_BYPASS,
        (string) ($bypass['code'] ?? '')
    );

    // Release lock from successful run
    if ($jid !== '') {
        orange_cpr_lock_release($env, $jid, [
            'lease_token' => (string) ($run['lease_token'] ?? ''),
            'worker_id' => (string) ($run['worker_id'] ?? ''),
        ]);
    }

    cpr_p4i('ops_enablement_still_false', orange_cpr_enablement_flag_read($env) === false);
} catch (Throwable $e) {
    cpr_p4i('exception', false, $e->getMessage());
} finally {
    cpr_p4i_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
