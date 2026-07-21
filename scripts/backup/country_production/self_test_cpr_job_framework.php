<?php

declare(strict_types=1);

/**
 * Self-test: CPR job framework scaffolding (WP-P3-02).
 * Run: php scripts/backup/country_production/self_test_cpr_job_framework.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_job_framework.php';

$pass = 0;
$fail = 0;

function cpr_t(string $name, bool $ok, string $detail = ''): void
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

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p302_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0775, true);
$env = [
    'ORANGE_CPR_WORK_DIR' => $tmp,
    'ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false,
];

try {
    cpr_t('enablement_false', orange_cpr_enablement_flag_read($env) === false);

    $job = orange_cpr_job_create($env, [
        'package_id' => 'pkg-test-1',
        'package_fingerprint' => str_repeat('a', 32),
        'country_id' => 1,
        'country_code' => 'KW',
        'workflow' => 'A',
    ], 99);

    cpr_t('job_id_uuid', (bool) preg_match('/^[a-f0-9-]{36}$/i', (string) $job['job_id']));
    cpr_t('state_pending', ($job['state'] ?? '') === ORANGE_CPR_STATE_PENDING);
    cpr_t('ponr_false', ($job['ponr_crossed'] ?? true) === false);
    cpr_t('enablement_observed_false', ($job['enablement_flag_observed'] ?? true) === false);
    cpr_t('mutation_engines_off', ($job['mutation_engines']['delete'] ?? true) === false
        && ($job['mutation_engines']['import'] ?? true) === false
        && ($job['mutation_engines']['ponr'] ?? true) === false);

    $read = orange_cpr_job_read(orange_cpr_resolve_work_root($env), (string) $job['job_id']);
    cpr_t('job_read', ($read['job_id'] ?? '') === $job['job_id']);

    $list = orange_cpr_job_list($env);
    cpr_t('job_list', count($list) >= 1);

    $fp = [
        'schema_revision_expected' => 121,
        'boundary_policy_version' => 'C1.1',
        'dependency_graph_version' => '1',
        'registry_revision' => 121,
        'c4_report_hash' => str_repeat('b', 32),
        'c5_report_hash' => str_repeat('c', 32),
        'c6_report_hash' => str_repeat('d', 32),
        'c7_report_hash' => str_repeat('e', 32),
        'c8_report_hash' => str_repeat('f', 32),
        'c8_overall_result' => 'SAFE',
        'inventory_snapshot_id' => 'inv-1',
        'inventory_snapshot_hash' => str_repeat('1', 32),
        'production_db_identity_hash' => str_repeat('2', 32),
    ];
    $contract = orange_cpr_contract_freeze_initial($env, (string) $job['job_id'], $fp, 99);
    cpr_t('contract_frozen', ($contract['contract_frozen'] ?? false) === true);
    cpr_t('contract_pre_pin', ($contract['contract_phase'] ?? '') === 'pre_pin');
    cpr_t(
        'contract_no_pin',
        array_key_exists('session_full_backup_id', $contract) && $contract['session_full_backup_id'] === null
    );
    cpr_t('contract_ponr_false', ($contract['ponr_authorized'] ?? true) === false);

    $job2 = orange_cpr_job_read(orange_cpr_resolve_work_root($env), (string) $job['job_id']);
    cpr_t('state_contract_frozen', ($job2['state'] ?? '') === ORANGE_CPR_STATE_CONTRACT_FROZEN);

    $cancelled = orange_cpr_job_cancel($env, (string) $job['job_id'], 99, 'test');
    cpr_t('cancelled', ($cancelled['state'] ?? '') === ORANGE_CPR_STATE_CANCELLED_PRE_PONR);

    $ponrBlocked = false;
    try {
        orange_cpr_forbidden_ponr();
    } catch (RuntimeException) {
        $ponrBlocked = true;
    }
    cpr_t('ponr_stub_blocks', $ponrBlocked);

    $deleteBlocked = false;
    try {
        orange_cpr_forbidden_delete_engine();
    } catch (RuntimeException) {
        $deleteBlocked = true;
    }
    cpr_t('delete_stub_blocks', $deleteBlocked);

    $badC8 = false;
    $jobB = orange_cpr_job_create($env, [
        'package_id' => 'pkg-test-2',
        'package_fingerprint' => str_repeat('9', 32),
        'country_id' => 2,
        'country_code' => 'SA',
        'workflow' => 'B',
    ], 1);
    try {
        $fp['c8_overall_result'] = 'WARNING';
        orange_cpr_contract_freeze_initial($env, (string) $jobB['job_id'], $fp, 1);
    } catch (RuntimeException) {
        $badC8 = true;
    }
    cpr_t('reject_c8_warning', $badC8);

    $envTrue = $env;
    $envTrue['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED'] = true;
    $blockedCreate = false;
    try {
        orange_cpr_job_create($envTrue, [
            'package_id' => 'x',
            'package_fingerprint' => str_repeat('a', 32),
            'country_id' => 1,
            'country_code' => 'KW',
            'workflow' => 'A',
        ], 1);
    } catch (RuntimeException) {
        $blockedCreate = true;
    }
    cpr_t('create_blocked_if_enablement_true', $blockedCreate);
} catch (Throwable $e) {
    cpr_t('suite_exception', false, $e->getMessage());
}

// cleanup best-effort
if (is_dir($tmp)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($tmp);
}

echo "\n{$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
