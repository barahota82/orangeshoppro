<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Evidence Pack Assembly & Seal (WP-P7-04).
 * Run: php scripts/backup/country_production/self_test_cpr_evidence_pack_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_evidence_pack_live.php';

$pass = 0;
$fail = 0;

function cpr_ev(string $name, bool $ok, string $detail = ''): void
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
 * @return array{env:array<string,mixed>,clone_root:string}
 */
function cpr_ev_fresh_env(string $base): array
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
        'clone_root' => $cloneRoot,
        'env' => [
            'ORANGE_CPR_WORK_DIR' => $cpr,
            'ORANGE_RESTORE_WORK_DIR' => $restoreWork,
            'ORANGE_BACKUP_ROOT' => $backupRoot,
            'ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false,
        ],
    ];
}

function cpr_ev_cleanup(string $base): void
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
function cpr_ev_clone_env(string $cloneRoot, array $extra = []): array
{
    return array_merge([
        'clone_environment_id' => 'clone-env-kw-p704',
        'drill_context' => 'clone',
        'clone_work_root' => $cloneRoot,
        'schema_revision' => 121,
        'country_id' => 1,
        'country_code' => 'KW',
        'package_id' => 'drill-pkg-p704',
        'package_fingerprint' => str_repeat('e', 32),
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
function cpr_ev_req(array $cloneEnv, array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'schema_revision' => (int) ($cloneEnv['schema_revision'] ?? 121),
        'country_id' => (int) ($cloneEnv['country_id'] ?? 1),
        'country_code' => (string) ($cloneEnv['country_code'] ?? 'KW'),
        'package_id' => (string) ($cloneEnv['package_id'] ?? 'drill-pkg-p704'),
        'package_fingerprint' => (string) ($cloneEnv['package_fingerprint'] ?? ''),
        'clone_environment' => $cloneEnv,
    ], $extra);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $cloneEnv
 * @return array{job_id:string,cpr_root:string}
 */
function cpr_ev_prepare_drills(array $env, array $cloneEnv): array
{
    $h = orange_cpr_drill_harness_live_run($env, cpr_ev_req($cloneEnv));
    if (empty($h['ok'])) {
        throw new RuntimeException('harness: ' . (string) ($h['code'] ?? ''));
    }
    $jid = (string) ($h['job_id'] ?? '');
    $dx = orange_cpr_drill_execution_live_run($env, cpr_ev_req($cloneEnv, ['job_id' => $jid]));
    if (empty($dx['ok'])) {
        throw new RuntimeException('drill: ' . (string) ($dx['code'] ?? ''));
    }

    return [
        'job_id' => $jid,
        'cpr_root' => orange_cpr_resolve_work_root($env),
    ];
}

/**
 * @param array<string, mixed> $record
 */
function cpr_ev_rewrite_latest(string $path, array $record): void
{
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('rewrite failed');
    }
    orange_cpr_atomic_rename_replace($tmp, $path);
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p704_' . bin2hex(random_bytes(4));
@mkdir($base, 0775, true);

try {
    cpr_ev('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P8-03-owner-cert-decision');
    cpr_ev('dirname_constant', ORANGE_CPR_EVIDENCE_PACK_DIRNAME === 'evidence_pack');
    cpr_ev(
        'catalog_count',
        count(orange_cpr_evidence_catalog_ids()) === ORANGE_CPR_EVIDENCE_CLASS_COUNT
    );
    cpr_ev(
        'control_plane_evidence_flag',
        !empty(orange_cpr_p7_control_plane_snapshot()['evidence_pack_engine_implemented'])
    );
    cpr_ev(
        'control_plane_freeze_flag_present',
        !empty(orange_cpr_p7_control_plane_snapshot()['p7_integration_baseline_complete'])
    );

    $src = (string) file_get_contents(
        dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_evidence_pack_live.php'
    );
    cpr_ev(
        'proof_no_production_resource_access_in_source',
        !preg_match('/\bdb\s*\(|\bPDO\b|\bmysqli_/i', $src)
        && str_contains($src, "'production_resources_accessed' => false")
    );

    // --- Valid EV-01…EV-14 assembly ---
    $ctx = cpr_ev_fresh_env($base);
    $env = $ctx['env'];
    $cloneEnv = cpr_ev_clone_env($ctx['clone_root']);
    $prep = cpr_ev_prepare_drills($env, $cloneEnv);
    $jid = $prep['job_id'];
    $cprRoot = $prep['cpr_root'];
    $run = orange_cpr_evidence_pack_live_run($env, cpr_ev_req($cloneEnv, ['job_id' => $jid]));
    cpr_ev('valid_ev01_ev14_assembly', !empty($run['ok']), (string) ($run['code'] ?? ''));
    cpr_ev('sealed_flag', !empty($run['sealed']));
    cpr_ev('artifact_count_14', (int) ($run['artifact_count'] ?? 0) === 14);
    $fps = is_array($run['evidence_fingerprints'] ?? null) ? $run['evidence_fingerprints'] : [];
    $fpOk = count($fps) === 14;
    foreach (orange_cpr_evidence_catalog_ids() as $ev) {
        if (!isset($fps[$ev]) || strlen((string) $fps[$ev]) !== 64) {
            $fpOk = false;
            break;
        }
    }
    cpr_ev('evidence_fingerprint_integrity', $fpOk);
    $manifest = is_array($run['manifest'] ?? null) ? $run['manifest'] : null;
    $seal = is_array($run['seal'] ?? null) ? $run['seal'] : null;
    $pack = is_array($run['pack_report'] ?? null) ? $run['pack_report'] : null;
    cpr_ev(
        'sealed_evidence_manifest',
        is_array($manifest) && !empty($manifest['sealed']) && orange_cpr_auth_verify_seal($manifest)
    );
    cpr_ev(
        'sealed_evidence_pack_seal',
        is_array($seal) && ($seal['post_seal_mutation_allowed'] ?? true) === false && orange_cpr_auth_verify_seal($seal)
    );
    cpr_ev(
        'sealed_pack_report',
        is_array($pack) && !empty($pack['sealed']) && orange_cpr_auth_verify_seal($pack)
    );
    cpr_ev(
        'packaging_order_deterministic',
        is_array($manifest)
        && ($manifest['evidence_classes'] ?? null) === orange_cpr_evidence_catalog_ids()
        && count($manifest['packaging_order'] ?? []) === 14
    );
    $recovery = is_array($run['recovery_metadata'] ?? null) ? $run['recovery_metadata'] : [];
    cpr_ev(
        'recovery_metadata_integrity',
        ($recovery['job_id'] ?? '') === $jid
        && ($recovery['completed_phase'] ?? '') === 'p7_evidence_pack_sealed'
        && ($recovery['owner_cert_pending'] ?? false) === true
        && (int) ($recovery['evidence_classes_count'] ?? 0) === 14
        && ($recovery['production_resources_accessed'] ?? true) === false
    );
    $audit = (string) file_get_contents($cprRoot . DIRECTORY_SEPARATOR . $jid . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME);
    cpr_ev(
        'audit_integrity',
        str_contains($audit, 'cpr.evidence_pack_live_start')
        && str_contains($audit, 'cpr.evidence_pack_live_complete')
        && str_contains($audit, '"production_resources_accessed":false')
    );
    cpr_ev(
        'proof_production_resources_never_accessed',
        ($run['production_resources_accessed'] ?? true) === false
        && ($run['production_sql_executed'] ?? true) === false
        && ($run['owner_cert_pass_granted'] ?? true) === false
    );

    $again = orange_cpr_evidence_pack_live_run($env, cpr_ev_req($cloneEnv, ['job_id' => $jid]));
    cpr_ev('idempotent_sealed', !empty($again['ok']) && !empty($again['idempotent']));
    $replay = orange_cpr_evidence_pack_live_run(
        $env,
        cpr_ev_req($cloneEnv, ['job_id' => $jid, 'force_replay' => true])
    );
    cpr_ev(
        'replay_attempt',
        empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_EVLIVE_ERR_REPLAY
    );

    // --- Missing evidence item ---
    $ctx2 = cpr_ev_fresh_env($base);
    $ce2 = cpr_ev_clone_env($ctx2['clone_root']);
    $p2 = cpr_ev_prepare_drills($ctx2['env'], $ce2);
    $missing = orange_cpr_evidence_pack_live_run(
        $ctx2['env'],
        cpr_ev_req($ce2, [
            'job_id' => $p2['job_id'],
            'evidence_classes' => ['EV-01', 'EV-02', 'EV-ZZZ'],
        ])
    );
    cpr_ev(
        'missing_evidence_item',
        empty($missing['ok']) && ($missing['code'] ?? '') === ORANGE_CPR_EVLIVE_ERR_MISSING,
        (string) ($missing['code'] ?? '')
    );

    // --- Invalid ordering ---
    $ctx3 = cpr_ev_fresh_env($base);
    $ce3 = cpr_ev_clone_env($ctx3['clone_root']);
    $p3 = cpr_ev_prepare_drills($ctx3['env'], $ce3);
    $ids = orange_cpr_evidence_catalog_ids();
    $reordered = $ids;
    $tmpSwap = $reordered[0];
    $reordered[0] = $reordered[1];
    $reordered[1] = $tmpSwap;
    $orderFail = orange_cpr_evidence_pack_live_run(
        $ctx3['env'],
        cpr_ev_req($ce3, [
            'job_id' => $p3['job_id'],
            'evidence_classes' => $reordered,
        ])
    );
    cpr_ev(
        'invalid_ordering',
        empty($orderFail['ok']) && ($orderFail['code'] ?? '') === ORANGE_CPR_EVLIVE_ERR_ORDER
    );
    $omit = $ids;
    array_splice($omit, 5, 1);
    $omitFail = orange_cpr_evidence_pack_live_run(
        $ctx3['env'],
        cpr_ev_req($ce3, [
            'job_id' => $p3['job_id'],
            'evidence_classes' => $omit,
        ])
    );
    cpr_ev(
        'invalid_ordering_omit',
        empty($omitFail['ok']) && ($omitFail['code'] ?? '') === ORANGE_CPR_EVLIVE_ERR_ORDER
    );

    // --- Corrupt evidence (break seal without rehash) ---
    $ctx4 = cpr_ev_fresh_env($base);
    $ce4 = cpr_ev_clone_env($ctx4['clone_root']);
    $p4 = cpr_ev_prepare_drills($ctx4['env'], $ce4);
    $path4 = orange_cpr_drill_execution_live_latest_path($p4['cpr_root'], $p4['job_id'], 'scenario_DS-N01');
    $raw4 = json_decode((string) file_get_contents($path4), true);
    if (is_array($raw4)) {
        $raw4['title'] = 'tampered-corrupt';
        // leave content_sha256 stale → corrupt
        cpr_ev_rewrite_latest($path4, $raw4);
    }
    $corrupt = orange_cpr_evidence_pack_live_run(
        $ctx4['env'],
        cpr_ev_req($ce4, ['job_id' => $p4['job_id']])
    );
    cpr_ev(
        'corrupt_evidence',
        empty($corrupt['ok']) && ($corrupt['code'] ?? '') === ORANGE_CPR_EVLIVE_ERR_CORRUPT,
        (string) ($corrupt['code'] ?? '')
    );

    // --- Modified evidence (re-seal after content change → fingerprint mismatch) ---
    $ctx5 = cpr_ev_fresh_env($base);
    $ce5 = cpr_ev_clone_env($ctx5['clone_root']);
    $p5 = cpr_ev_prepare_drills($ctx5['env'], $ce5);
    $path5 = orange_cpr_drill_execution_live_latest_path($p5['cpr_root'], $p5['job_id'], 'scenario_DS-N01');
    $raw5 = json_decode((string) file_get_contents($path5), true);
    if (is_array($raw5)) {
        unset($raw5['content_sha256']);
        $raw5['expected_outcome'] = 'tampered-modified-outcome';
        cpr_ev_rewrite_latest($path5, orange_cpr_auth_seal($raw5));
    }
    $modified = orange_cpr_evidence_pack_live_run(
        $ctx5['env'],
        cpr_ev_req($ce5, ['job_id' => $p5['job_id']])
    );
    cpr_ev(
        'modified_evidence',
        empty($modified['ok']) && ($modified['code'] ?? '') === ORANGE_CPR_EVLIVE_ERR_MODIFIED,
        (string) ($modified['code'] ?? '')
    );

    // --- Contract / country / fingerprint mismatch ---
    $ctx6 = cpr_ev_fresh_env($base);
    $ce6 = cpr_ev_clone_env($ctx6['clone_root']);
    $p6 = cpr_ev_prepare_drills($ctx6['env'], $ce6);
    $fpFail = orange_cpr_evidence_pack_live_run(
        $ctx6['env'],
        cpr_ev_req($ce6, [
            'job_id' => $p6['job_id'],
            'package_fingerprint' => str_repeat('f', 32),
        ])
    );
    cpr_ev(
        'fingerprint_mismatch',
        empty($fpFail['ok']) && ($fpFail['code'] ?? '') === ORANGE_CPR_EVLIVE_ERR_FINGERPRINT
    );
    $countryFail = orange_cpr_evidence_pack_live_run(
        $ctx6['env'],
        cpr_ev_req($ce6, [
            'job_id' => $p6['job_id'],
            'country_id' => 2,
            'country_code' => 'SA',
        ])
    );
    cpr_ev(
        'country_mismatch',
        empty($countryFail['ok']) && ($countryFail['code'] ?? '') === ORANGE_CPR_EVLIVE_ERR_COUNTRY
    );
    $schemaFail = orange_cpr_evidence_pack_live_run(
        $ctx6['env'],
        cpr_ev_req($ce6, [
            'job_id' => $p6['job_id'],
            'schema_revision' => 999,
        ])
    );
    cpr_ev(
        'contract_mismatch',
        empty($schemaFail['ok']) && ($schemaFail['code'] ?? '') === ORANGE_CPR_EVLIVE_ERR_CONTRACT
    );

    $assert = orange_cpr_p7_control_plane_assert(['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false]);
    cpr_ev('control_plane_assert_ok_after_evidence', !empty($assert['ok']), (string) ($assert['code'] ?? ''));
} catch (Throwable $e) {
    cpr_ev('exception', false, $e->getMessage());
} finally {
    cpr_ev_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
