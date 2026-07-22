<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Clone Drill Harness & Environment Binding (WP-P7-02).
 * Run: php scripts/backup/country_production/self_test_cpr_drill_harness_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_drill_harness_live.php';

$pass = 0;
$fail = 0;

function cpr_dh(string $name, bool $ok, string $detail = ''): void
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
 * @return array{env:array<string,mixed>,cpr:string,clone_root:string}
 */
function cpr_dh_fresh_env(string $base): array
{
    $id = bin2hex(random_bytes(3));
    $restoreWork = $base . DIRECTORY_SEPARATOR . 'rw_' . $id;
    $cpr = $restoreWork . DIRECTORY_SEPARATOR . 'country_production';
    $cloneRoot = $base . DIRECTORY_SEPARATOR . 'clone_' . $id;
    $backupRoot = $base . DIRECTORY_SEPARATOR . 'br_' . $id;
    @mkdir($cpr, 0775, true);
    @mkdir($cloneRoot, 0775, true);
    @mkdir($backupRoot . DIRECTORY_SEPARATOR . 'locks', 0775, true);

    return [
        'cpr' => $cpr,
        'clone_root' => $cloneRoot,
        'env' => [
            'ORANGE_CPR_WORK_DIR' => $cpr,
            'ORANGE_RESTORE_WORK_DIR' => $restoreWork,
            'ORANGE_BACKUP_ROOT' => $backupRoot,
            'ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false,
        ],
    ];
}

function cpr_dh_cleanup(string $base): void
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
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function cpr_dh_clone_env(string $cloneRoot, array $extra = []): array
{
    $fp = str_repeat('a', 32);

    return array_merge([
        'clone_environment_id' => 'clone-env-kw-01',
        'drill_context' => 'clone',
        'clone_work_root' => $cloneRoot,
        'schema_revision' => 121,
        'country_id' => 1,
        'country_code' => 'KW',
        'package_id' => 'drill-pkg-p702',
        'package_fingerprint' => $fp,
        'isolation_confirmed' => true,
        'production_db_access' => false,
        'production_uploads_access' => false,
        'production_services_access' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $cloneEnv
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function cpr_dh_req(array $cloneEnv, array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'schema_revision' => (int) ($cloneEnv['schema_revision'] ?? 121),
        'country_id' => (int) ($cloneEnv['country_id'] ?? 1),
        'country_code' => (string) ($cloneEnv['country_code'] ?? 'KW'),
        'package_id' => (string) ($cloneEnv['package_id'] ?? 'drill-pkg-p702'),
        'package_fingerprint' => (string) ($cloneEnv['package_fingerprint'] ?? ''),
        'clone_environment' => $cloneEnv,
    ], $extra);
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p702_' . bin2hex(random_bytes(4));
@mkdir($base, 0775, true);

try {
    cpr_dh('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P9-04-integration-baseline');
    cpr_dh('dirname_constant', ORANGE_CPR_DRILL_HARNESS_DIRNAME === 'drill_harness');
    cpr_dh(
        'control_plane_harness_flag',
        !empty(orange_cpr_p7_control_plane_snapshot()['drill_harness_implemented'])
    );
    cpr_dh(
        'control_plane_execution_flag_present',
        !empty(orange_cpr_p7_control_plane_snapshot()['drill_execution_engine_implemented'])
    );

    $src = (string) file_get_contents(
        dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_drill_harness_live.php'
    );
    cpr_dh(
        'proof_no_production_resource_access_in_source',
        !preg_match('/\bdb\s*\(|\bPDO\b|\bmysqli_/i', $src)
        && !preg_match('/\b(?:new\s+PDO|mysqli_connect)\b/i', $src)
        && str_contains($src, 'use_production_db')
        && str_contains($src, 'ORANGE_CPR_DHLIVE_ERR_BYPASS')
        && str_contains($src, 'production_resources_accessed')
        && str_contains($src, "'production_resources_accessed' => false")
    );

    // --- Valid clone environment ---
    $ctx = cpr_dh_fresh_env($base);
    $env = $ctx['env'];
    $cloneEnv = cpr_dh_clone_env($ctx['clone_root']);
    $run = orange_cpr_drill_harness_live_run($env, cpr_dh_req($cloneEnv));
    cpr_dh('valid_clone_environment', !empty($run['ok']), (string) ($run['code'] ?? ''));
    $jid = (string) ($run['job_id'] ?? '');
    $cprRoot = orange_cpr_resolve_work_root($env);
    cpr_dh('job_id_bound', $jid !== '');
    $binding = is_array($run['environment_binding'] ?? null) ? $run['environment_binding'] : null;
    $harness = is_array($run['harness_report'] ?? null) ? $run['harness_report'] : null;
    cpr_dh(
        'sealed_environment_binding_report',
        is_array($binding)
        && !empty($binding['binding_complete'])
        && orange_cpr_auth_verify_seal($binding)
    );
    cpr_dh(
        'sealed_drill_harness_report',
        is_array($harness)
        && !empty($harness['harness_bound'])
        && ($harness['scenario_executed'] ?? true) === false
        && orange_cpr_auth_verify_seal($harness)
    );
    cpr_dh(
        'bind_job_contract_country_schema_env',
        is_array($binding)
        && ($binding['job_id'] ?? '') === $jid
        && !empty($binding['contract_frozen'])
        && (int) ($binding['country_id'] ?? 0) === 1
        && (int) ($binding['schema_revision_expected'] ?? 0) === 121
        && ($binding['clone_environment_id'] ?? '') === 'clone-env-kw-01'
        && ($binding['drill_context'] ?? '') === 'clone'
    );
    cpr_dh(
        'isolation_flags',
        is_array($binding)
        && ($binding['environment_isolated'] ?? false) === true
        && ($binding['production_db_access'] ?? true) === false
        && ($binding['production_uploads_access'] ?? true) === false
        && ($binding['production_services_access'] ?? true) === false
    );
    cpr_dh('no_production_sql', ($run['production_sql_executed'] ?? true) === false);
    cpr_dh(
        'proof_production_resources_never_accessed',
        ($run['production_resources_accessed'] ?? true) === false
        && ($run['production_mutation'] ?? true) === false
        && ($run['production_uploads_mutated'] ?? true) === false
        && ($run['enablement_flag_observed'] ?? true) === false
        && ($run['scenario_executed'] ?? true) === false
    );
    $recovery = is_array($run['recovery_metadata'] ?? null) ? $run['recovery_metadata'] : [];
    cpr_dh(
        'recovery_metadata_integrity',
        ($recovery['job_id'] ?? '') === $jid
        && ($recovery['completed_phase'] ?? '') === 'p7_drill_harness_bound'
        && ($recovery['execution_status'] ?? '') === 'environment_bound'
        && ($recovery['scenario_execution_not_started'] ?? false) === true
        && ($recovery['production_resources_accessed'] ?? true) === false
        && ($recovery['p8_not_started'] ?? false) === true
        && ($recovery['clone_environment_id'] ?? '') === 'clone-env-kw-01'
    );
    $auditPath = $cprRoot . DIRECTORY_SEPARATOR . $jid . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME;
    $audit = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
    cpr_dh(
        'audit_integrity',
        str_contains($audit, 'cpr.drill_harness_live_bind')
        && str_contains($audit, 'cpr.drill_harness_live_complete')
        && str_contains($audit, '"production_resources_accessed":false')
    );
    $harnessDir = orange_cpr_drill_harness_directory($cprRoot, $jid);
    cpr_dh('harness_directory_created', is_dir($harnessDir));

    // Idempotent + replay refuse
    $again = orange_cpr_drill_harness_live_run($env, cpr_dh_req($cloneEnv, ['job_id' => $jid]));
    cpr_dh(
        'idempotent_rebinding',
        !empty($again['ok']) && !empty($again['idempotent']) && !empty($again['exactly_once'])
    );
    $replay = orange_cpr_drill_harness_live_run(
        $env,
        cpr_dh_req($cloneEnv, ['job_id' => $jid, 'force_replay' => true])
    );
    cpr_dh(
        'replay_attempt',
        empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_DHLIVE_ERR_REPLAY
    );

    // --- Invalid environment ---
    $ctx2 = cpr_dh_fresh_env($base);
    $badEnv = cpr_dh_clone_env($ctx2['clone_root'], [
        'clone_environment_id' => 'x',
        'isolation_confirmed' => false,
        'environment_isolated' => false,
    ]);
    $invalid = orange_cpr_drill_harness_live_run($ctx2['env'], cpr_dh_req($badEnv));
    cpr_dh(
        'invalid_environment',
        empty($invalid['ok'])
        && in_array(($invalid['code'] ?? ''), [
            ORANGE_CPR_DHLIVE_ERR_ENV,
            ORANGE_CPR_DHLIVE_ERR_ISOLATION,
        ], true),
        (string) ($invalid['code'] ?? '')
    );

    // --- Production endpoint detection ---
    $ctx3 = cpr_dh_fresh_env($base);
    $prodEnv = cpr_dh_clone_env($ctx3['clone_root'], [
        'production_db_dsn' => 'mysql:host=prod.example;dbname=orange',
        'is_production' => true,
    ]);
    $prod = orange_cpr_drill_harness_live_run($ctx3['env'], cpr_dh_req($prodEnv));
    cpr_dh(
        'production_endpoint_detection',
        empty($prod['ok']) && ($prod['code'] ?? '') === ORANGE_CPR_DHLIVE_ERR_PRODUCTION,
        (string) ($prod['code'] ?? '')
    );
    $hits = orange_cpr_drill_harness_live_detect_production_markers($prodEnv);
    cpr_dh(
        'production_markers_list',
        in_array('is_production_flag', $hits, true) && in_array('production_db_dsn', $hits, true)
    );

    // --- Schema mismatch (existing job + frozen contract) ---
    $ctx4 = cpr_dh_fresh_env($base);
    $okEnv4 = cpr_dh_clone_env($ctx4['clone_root']);
    $setup4 = orange_cpr_drill_harness_live_run($ctx4['env'], cpr_dh_req($okEnv4));
    cpr_dh('schema_setup_ok', !empty($setup4['ok']), (string) ($setup4['code'] ?? ''));
    $jid4 = (string) ($setup4['job_id'] ?? '');
    // Clear harness so mismatch path is exercised (not idempotent)
    $hDir4 = orange_cpr_drill_harness_directory(orange_cpr_resolve_work_root($ctx4['env']), $jid4);
    if (is_dir($hDir4)) {
        foreach (glob($hDir4 . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
    }
    $mismatchSchemaEnv = cpr_dh_clone_env($ctx4['clone_root'], ['schema_revision' => 999]);
    $schemaFail = orange_cpr_drill_harness_live_run(
        $ctx4['env'],
        cpr_dh_req($mismatchSchemaEnv, [
            'job_id' => $jid4,
            'schema_revision' => 999,
        ])
    );
    cpr_dh(
        'schema_mismatch',
        empty($schemaFail['ok']) && ($schemaFail['code'] ?? '') === ORANGE_CPR_DHLIVE_ERR_SCHEMA,
        (string) ($schemaFail['code'] ?? '')
    );

    // --- Country mismatch ---
    $ctx5 = cpr_dh_fresh_env($base);
    $okEnv5 = cpr_dh_clone_env($ctx5['clone_root']);
    $setup5 = orange_cpr_drill_harness_live_run($ctx5['env'], cpr_dh_req($okEnv5));
    $jid5 = (string) ($setup5['job_id'] ?? '');
    $hDir5 = orange_cpr_drill_harness_directory(orange_cpr_resolve_work_root($ctx5['env']), $jid5);
    if (is_dir($hDir5)) {
        foreach (glob($hDir5 . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
    }
    $countryEnv = cpr_dh_clone_env($ctx5['clone_root'], [
        'country_id' => 2,
        'country_code' => 'SA',
    ]);
    $countryFail = orange_cpr_drill_harness_live_run(
        $ctx5['env'],
        cpr_dh_req($countryEnv, [
            'job_id' => $jid5,
            'country_id' => 2,
            'country_code' => 'SA',
        ])
    );
    cpr_dh(
        'country_mismatch',
        empty($countryFail['ok']) && ($countryFail['code'] ?? '') === ORANGE_CPR_DHLIVE_ERR_COUNTRY,
        (string) ($countryFail['code'] ?? '')
    );

    // --- Contract mismatch (job without frozen contract) ---
    $ctx6 = cpr_dh_fresh_env($base);
    $fp6 = str_repeat('a', 32);
    $job6 = orange_cpr_job_create($ctx6['env'], [
        'package_id' => 'drill-pkg-p702',
        'package_fingerprint' => $fp6,
        'country_id' => 1,
        'country_code' => 'KW',
        'workflow' => 'B',
    ], 7);
    $jid6 = (string) ($job6['job_id'] ?? '');
    $contractFail = orange_cpr_drill_harness_live_run(
        $ctx6['env'],
        cpr_dh_req(cpr_dh_clone_env($ctx6['clone_root']), ['job_id' => $jid6])
    );
    cpr_dh(
        'contract_mismatch',
        empty($contractFail['ok']) && ($contractFail['code'] ?? '') === ORANGE_CPR_DHLIVE_ERR_CONTRACT,
        (string) ($contractFail['code'] ?? '')
    );

    // Privilege bypass + enablement
    $ctx7 = cpr_dh_fresh_env($base);
    $bypass = orange_cpr_drill_harness_live_run(
        $ctx7['env'],
        cpr_dh_req(cpr_dh_clone_env($ctx7['clone_root']), [
            'actor_is_super_admin' => false,
        ])
    );
    cpr_dh(
        'privilege_bypass_actor',
        empty($bypass['ok']) && ($bypass['code'] ?? '') === ORANGE_CPR_DHLIVE_ERR_ACTOR
    );
    $knob = orange_cpr_drill_harness_live_run(
        $ctx7['env'],
        cpr_dh_req(cpr_dh_clone_env($ctx7['clone_root']), ['force_pass' => true])
    );
    cpr_dh(
        'privilege_bypass_knob',
        empty($knob['ok']) && ($knob['code'] ?? '') === ORANGE_CPR_DHLIVE_ERR_BYPASS
    );
    $envTrue = $ctx7['env'];
    $envTrue['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED'] = true;
    $enFail = orange_cpr_drill_harness_live_run(
        $envTrue,
        cpr_dh_req(cpr_dh_clone_env($ctx7['clone_root']))
    );
    cpr_dh(
        'enablement_false_enforced',
        empty($enFail['ok']) && ($enFail['code'] ?? '') === ORANGE_CPR_DHLIVE_ERR_ENABLEMENT
    );

    // Invalid drill_context
    $ctx8 = cpr_dh_fresh_env($base);
    $ctxFail = orange_cpr_drill_harness_live_run(
        $ctx8['env'],
        cpr_dh_req(cpr_dh_clone_env($ctx8['clone_root'], ['drill_context' => 'production']))
    );
    cpr_dh(
        'invalid_drill_context',
        empty($ctxFail['ok']) && ($ctxFail['code'] ?? '') === ORANGE_CPR_DHLIVE_ERR_CONTEXT
    );

    $assert = orange_cpr_p7_control_plane_assert(['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false]);
    cpr_dh('control_plane_assert_ok_after_harness', !empty($assert['ok']), (string) ($assert['code'] ?? ''));
} catch (Throwable $e) {
    cpr_dh('exception', false, $e->getMessage());
} finally {
    cpr_dh_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
