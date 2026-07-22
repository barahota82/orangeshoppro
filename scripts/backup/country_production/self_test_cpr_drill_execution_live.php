<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Drill Scenario Execution (WP-P7-03 / P2-03 DS-*).
 * Run: php scripts/backup/country_production/self_test_cpr_drill_execution_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_drill_execution_live.php';

$pass = 0;
$fail = 0;

function cpr_dx(string $name, bool $ok, string $detail = ''): void
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
function cpr_dx_fresh_env(string $base): array
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

function cpr_dx_cleanup(string $base): void
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
function cpr_dx_clone_env(string $cloneRoot, array $extra = []): array
{
    return array_merge([
        'clone_environment_id' => 'clone-env-kw-p703',
        'drill_context' => 'clone',
        'clone_work_root' => $cloneRoot,
        'schema_revision' => 121,
        'country_id' => 1,
        'country_code' => 'KW',
        'package_id' => 'drill-pkg-p703',
        'package_fingerprint' => str_repeat('c', 32),
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
function cpr_dx_req(array $cloneEnv, array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'schema_revision' => (int) ($cloneEnv['schema_revision'] ?? 121),
        'country_id' => (int) ($cloneEnv['country_id'] ?? 1),
        'country_code' => (string) ($cloneEnv['country_code'] ?? 'KW'),
        'package_id' => (string) ($cloneEnv['package_id'] ?? 'drill-pkg-p703'),
        'package_fingerprint' => (string) ($cloneEnv['package_fingerprint'] ?? ''),
        'clone_environment' => $cloneEnv,
    ], $extra);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $cloneEnv
 * @return array{job_id:string,cpr_root:string}
 */
function cpr_dx_bind_harness(array $env, array $cloneEnv): array
{
    $h = orange_cpr_drill_harness_live_run($env, cpr_dx_req($cloneEnv));
    if (empty($h['ok'])) {
        throw new RuntimeException('harness: ' . (string) ($h['code'] ?? $h['message'] ?? ''));
    }

    return [
        'job_id' => (string) ($h['job_id'] ?? ''),
        'cpr_root' => orange_cpr_resolve_work_root($env),
    ];
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p703_' . bin2hex(random_bytes(4));
@mkdir($base, 0775, true);

try {
    cpr_dx('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P9-04-integration-baseline');
    cpr_dx('dirname_constant', ORANGE_CPR_DRILL_EXECUTION_DIRNAME === 'drill_execution');
    cpr_dx(
        'catalog_count',
        count(orange_cpr_drill_catalog_ids()) === ORANGE_CPR_DRILL_CATALOG_COUNT
        && count(orange_cpr_drill_catalog_definitions()) === ORANGE_CPR_DRILL_CATALOG_COUNT
    );
    cpr_dx(
        'control_plane_execution_flag',
        !empty(orange_cpr_p7_control_plane_snapshot()['drill_execution_engine_implemented'])
    );
    cpr_dx(
        'control_plane_evidence_flag_present',
        !empty(orange_cpr_p7_control_plane_snapshot()['evidence_pack_engine_implemented'])
    );

    $src = (string) file_get_contents(
        dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_drill_execution_live.php'
    );
    cpr_dx(
        'proof_no_production_resource_access_in_source',
        !preg_match('/\bdb\s*\(|\bPDO\b|\bmysqli_/i', $src)
        && str_contains($src, 'production_resources_accessed')
        && str_contains($src, "'production_resources_accessed' => false")
    );

    // --- Valid execution of every approved DS-* scenario ---
    $ctx = cpr_dx_fresh_env($base);
    $env = $ctx['env'];
    $cloneEnv = cpr_dx_clone_env($ctx['clone_root']);
    $bound = cpr_dx_bind_harness($env, $cloneEnv);
    $jid = $bound['job_id'];
    $cprRoot = $bound['cpr_root'];
    $run = orange_cpr_drill_execution_live_run($env, cpr_dx_req($cloneEnv, ['job_id' => $jid]));
    cpr_dx('valid_full_catalog_execution', !empty($run['ok']), (string) ($run['code'] ?? ''));
    cpr_dx('full_catalog_flag', !empty($run['full_catalog_executed']));
    cpr_dx(
        'every_ds_scenario_executed',
        (int) ($run['scenario_count'] ?? 0) === ORANGE_CPR_DRILL_CATALOG_COUNT
    );
    $fps = is_array($run['scenario_fingerprints'] ?? null) ? $run['scenario_fingerprints'] : [];
    $allFpOk = count($fps) === ORANGE_CPR_DRILL_CATALOG_COUNT;
    foreach (orange_cpr_drill_catalog_ids() as $sid) {
        if (!isset($fps[$sid]) || strlen((string) $fps[$sid]) !== 64) {
            $allFpOk = false;
            break;
        }
        $rep = orange_cpr_drill_execution_live_load_latest($cprRoot, $jid, 'scenario_' . $sid);
        if (!is_array($rep) || ($rep['result'] ?? '') !== 'PASS' || !orange_cpr_auth_verify_seal($rep)) {
            $allFpOk = false;
            break;
        }
    }
    cpr_dx('scenario_fingerprint_integrity', $allFpOk);
    $agg = is_array($run['aggregate_report'] ?? null) ? $run['aggregate_report'] : null;
    cpr_dx(
        'sealed_aggregate_drill_report',
        is_array($agg) && !empty($agg['execution_complete']) && orange_cpr_auth_verify_seal($agg)
    );
    $recovery = is_array($run['recovery_metadata'] ?? null) ? $run['recovery_metadata'] : [];
    cpr_dx(
        'recovery_metadata_integrity',
        ($recovery['job_id'] ?? '') === $jid
        && ($recovery['completed_phase'] ?? '') === 'p7_drill_scenarios_executed'
        && ($recovery['scenario_execution_not_started'] ?? true) === false
        && (int) ($recovery['scenarios_executed_count'] ?? 0) === ORANGE_CPR_DRILL_CATALOG_COUNT
        && ($recovery['evidence_pack_not_started'] ?? false) === true
        && ($recovery['production_resources_accessed'] ?? true) === false
        && ($recovery['auto_rollback_executed'] ?? true) === false
    );
    $audit = (string) file_get_contents($cprRoot . DIRECTORY_SEPARATOR . $jid . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME);
    cpr_dx(
        'audit_integrity',
        str_contains($audit, 'cpr.drill_execution_live_start')
        && str_contains($audit, 'cpr.drill_execution_live_scenario')
        && str_contains($audit, 'cpr.drill_execution_live_complete')
        && str_contains($audit, '"production_resources_accessed":false')
    );
    cpr_dx(
        'proof_production_resources_never_accessed',
        ($run['production_resources_accessed'] ?? true) === false
        && ($run['production_sql_executed'] ?? true) === false
        && ($run['production_mutation'] ?? true) === false
        && ($run['production_uploads_mutated'] ?? true) === false
        && ($run['enablement_flag_observed'] ?? true) === false
        && ($run['auto_rollback_executed'] ?? true) === false
    );
    cpr_dx(
        'integration_harness_state_checkpoint',
        is_array($agg)
        && ($agg['binding_id'] ?? '') !== ''
        && is_array($recovery['checkpoints_committed_observed'] ?? null)
        && is_array($recovery['scenario_fingerprints'] ?? null)
    );

    // Idempotent + replay
    $again = orange_cpr_drill_execution_live_run($env, cpr_dx_req($cloneEnv, ['job_id' => $jid]));
    cpr_dx(
        'idempotent_complete',
        !empty($again['ok']) && !empty($again['idempotent']) && !empty($again['exactly_once'])
    );
    $replay = orange_cpr_drill_execution_live_run(
        $env,
        cpr_dx_req($cloneEnv, ['job_id' => $jid, 'force_replay' => true])
    );
    cpr_dx(
        'replay_attempt',
        empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_DXLIVE_ERR_REPLAY
    );

    // --- Missing scenario definition ---
    $ctx2 = cpr_dx_fresh_env($base);
    $ce2 = cpr_dx_clone_env($ctx2['clone_root']);
    $b2 = cpr_dx_bind_harness($ctx2['env'], $ce2);
    $missing = orange_cpr_drill_execution_live_run(
        $ctx2['env'],
        cpr_dx_req($ce2, [
            'job_id' => $b2['job_id'],
            'scenario_ids' => ['DS-N01', 'DS-ZZZ'],
        ])
    );
    cpr_dx(
        'missing_scenario_definition',
        empty($missing['ok']) && ($missing['code'] ?? '') === ORANGE_CPR_DXLIVE_ERR_MISSING,
        (string) ($missing['code'] ?? '')
    );

    // --- Invalid scenario ordering (reorder / skip) ---
    $ctx3 = cpr_dx_fresh_env($base);
    $ce3 = cpr_dx_clone_env($ctx3['clone_root']);
    $b3 = cpr_dx_bind_harness($ctx3['env'], $ce3);
    $reorder = orange_cpr_drill_execution_live_run(
        $ctx3['env'],
        cpr_dx_req($ce3, [
            'job_id' => $b3['job_id'],
            'scenario_ids' => ['DS-F01', 'DS-N01'],
        ])
    );
    cpr_dx(
        'invalid_scenario_ordering_reorder',
        empty($reorder['ok']) && ($reorder['code'] ?? '') === ORANGE_CPR_DXLIVE_ERR_ORDER
    );
    $skip = orange_cpr_drill_execution_live_run(
        $ctx3['env'],
        cpr_dx_req($ce3, [
            'job_id' => $b3['job_id'],
            'scenario_ids' => ['DS-N01', 'DS-F02'],
        ])
    );
    cpr_dx(
        'invalid_scenario_ordering_skip',
        empty($skip['ok']) && ($skip['code'] ?? '') === ORANGE_CPR_DXLIVE_ERR_ORDER
    );

    // --- Clone environment mismatch ---
    $ctx4 = cpr_dx_fresh_env($base);
    $ce4 = cpr_dx_clone_env($ctx4['clone_root']);
    $b4 = cpr_dx_bind_harness($ctx4['env'], $ce4);
    $badEnv = cpr_dx_clone_env($ctx4['clone_root'], ['clone_environment_id' => 'other-clone-env']);
    $envMismatch = orange_cpr_drill_execution_live_run(
        $ctx4['env'],
        cpr_dx_req($badEnv, ['job_id' => $b4['job_id']])
    );
    cpr_dx(
        'clone_environment_mismatch',
        empty($envMismatch['ok']) && ($envMismatch['code'] ?? '') === ORANGE_CPR_DXLIVE_ERR_ENV,
        (string) ($envMismatch['code'] ?? '')
    );

    // --- Contract mismatch (fingerprint drift via binding vs request env) ---
    $ctx5 = cpr_dx_fresh_env($base);
    $ce5 = cpr_dx_clone_env($ctx5['clone_root']);
    $b5 = cpr_dx_bind_harness($ctx5['env'], $ce5);
    $fpDrift = cpr_dx_clone_env($ctx5['clone_root'], ['package_fingerprint' => str_repeat('d', 32)]);
    $contractFail = orange_cpr_drill_execution_live_run(
        $ctx5['env'],
        cpr_dx_req($fpDrift, [
            'job_id' => $b5['job_id'],
            'package_fingerprint' => str_repeat('d', 32),
        ])
    );
    cpr_dx(
        'contract_mismatch',
        empty($contractFail['ok'])
        && in_array(($contractFail['code'] ?? ''), [
            ORANGE_CPR_DXLIVE_ERR_CONTRACT,
            ORANGE_CPR_DXLIVE_ERR_ENV,
        ], true),
        (string) ($contractFail['code'] ?? '')
    );

    // --- Country mismatch / cross-country ---
    $ctx6 = cpr_dx_fresh_env($base);
    $ce6 = cpr_dx_clone_env($ctx6['clone_root']);
    $b6 = cpr_dx_bind_harness($ctx6['env'], $ce6);
    $countryFail = orange_cpr_drill_execution_live_run(
        $ctx6['env'],
        cpr_dx_req($ce6, [
            'job_id' => $b6['job_id'],
            'country_id' => 2,
            'country_code' => 'SA',
        ])
    );
    cpr_dx(
        'country_mismatch',
        empty($countryFail['ok']) && ($countryFail['code'] ?? '') === ORANGE_CPR_DXLIVE_ERR_COUNTRY
    );
    $cross = orange_cpr_drill_execution_live_run(
        $ctx6['env'],
        cpr_dx_req($ce6, [
            'job_id' => $b6['job_id'],
            'cross_country_country_id' => 99,
            'scenario_ids' => ['DS-N01'],
        ])
    );
    cpr_dx(
        'cross_country_forbidden',
        empty($cross['ok']) && ($cross['code'] ?? '') === ORANGE_CPR_DXLIVE_ERR_CROSS_COUNTRY
    );

    // Privilege / enablement
    $ctx7 = cpr_dx_fresh_env($base);
    $ce7 = cpr_dx_clone_env($ctx7['clone_root']);
    $b7 = cpr_dx_bind_harness($ctx7['env'], $ce7);
    $actorDeny = orange_cpr_drill_execution_live_run(
        $ctx7['env'],
        cpr_dx_req($ce7, [
            'job_id' => $b7['job_id'],
            'actor_is_super_admin' => false,
            'scenario_ids' => ['DS-N01'],
        ])
    );
    cpr_dx(
        'privilege_bypass_actor',
        empty($actorDeny['ok']) && ($actorDeny['code'] ?? '') === ORANGE_CPR_DXLIVE_ERR_ACTOR
    );
    $knob = orange_cpr_drill_execution_live_run(
        $ctx7['env'],
        cpr_dx_req($ce7, [
            'job_id' => $b7['job_id'],
            'force_pass' => true,
            'scenario_ids' => ['DS-N01'],
        ])
    );
    cpr_dx(
        'privilege_bypass_knob',
        empty($knob['ok']) && ($knob['code'] ?? '') === ORANGE_CPR_DXLIVE_ERR_BYPASS
    );

    $assert = orange_cpr_p7_control_plane_assert(['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false]);
    cpr_dx('control_plane_assert_ok_after_execution', !empty($assert['ok']), (string) ($assert['code'] ?? ''));
} catch (Throwable $e) {
    cpr_dx('exception', false, $e->getMessage());
} finally {
    cpr_dx_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
