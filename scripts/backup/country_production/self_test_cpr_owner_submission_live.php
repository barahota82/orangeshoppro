<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Owner Submission Package Assembly (WP-P8-02).
 * Run: php scripts/backup/country_production/self_test_cpr_owner_submission_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_owner_submission_live.php';

$pass = 0;
$fail = 0;

function cpr_osub(string $name, bool $ok, string $detail = ''): void
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
function cpr_osub_fresh_env(string $base): array
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

function cpr_osub_cleanup(string $base): void
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
 * @param array<string, mixed> $record
 */
function cpr_osub_rewrite(string $path, array $record): void
{
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('rewrite failed');
    }
    orange_cpr_atomic_rename_replace($tmp, $path);
}

/**
 * @param array<string, mixed> $env
 * @return array{job_id:string,cpr_root:string,fp:string}
 */
function cpr_osub_prepare_p7_baseline(array $env, string $cloneRoot): array
{
    $fp = str_repeat('8', 32);
    $run = orange_cpr_p7_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'clone_work_root' => $cloneRoot,
        'package_fingerprint' => $fp,
        'schema_revision' => 121,
        'country_id' => 1,
        'country_code' => 'KW',
        'package_id' => 'drill-pkg-p802',
        'clone_environment_id' => 'clone-env-p802',
        'drill_context' => 'clone',
    ]);
    if (empty($run['ok'])) {
        throw new RuntimeException('p7 baseline: ' . (string) ($run['code'] ?? ''));
    }

    return [
        'job_id' => (string) ($run['job_id'] ?? ''),
        'cpr_root' => orange_cpr_resolve_work_root($env),
        'fp' => $fp,
    ];
}

/**
 * @return array<string, mixed>
 */
function cpr_osub_req(string $jobId, string $fp, array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => 1,
        'country_code' => 'KW',
        'schema_revision' => 121,
    ], $extra);
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p802_' . bin2hex(random_bytes(4));
@mkdir($base, 0775, true);

try {
    cpr_osub('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P8-04-integration-baseline');
    cpr_osub('dirname_constant', ORANGE_CPR_OWNER_SUBMISSION_DIRNAME === 'owner_submission');
    cpr_osub(
        'section_order_count',
        count(orange_cpr_owner_submission_section_order()) === 13
    );
    cpr_osub(
        'control_plane_submission_flag',
        !empty(orange_cpr_p8_control_plane_snapshot()['owner_submission_engine_implemented'])
    );
    cpr_osub(
        'control_plane_decision_implemented_after_p8_03',
        !empty(orange_cpr_p8_control_plane_snapshot()['owner_cert_decision_engine_implemented'])
    );
    cpr_osub(
        'control_plane_freeze_complete_after_p8_04',
        !empty(orange_cpr_p8_control_plane_snapshot()['p8_integration_baseline_complete'])
    );

    $src = (string) file_get_contents(
        dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_owner_submission_live.php'
    );
    cpr_osub(
        'proof_no_production_sql_in_source',
        !preg_match('/\bdb\s*\(|\bPDO\b|\bmysqli_/i', $src)
        && str_contains($src, "'production_resources_accessed' => false")
    );
    cpr_osub(
        'proof_no_cert_decision_in_engine',
        str_contains($src, "'is_certification_decision' => false")
        && str_contains($src, 'ORANGE_CPR_OSUB_ERR_DECISION')
    );

    // --- Valid submission ---
    $ctx = cpr_osub_fresh_env($base);
    $env = $ctx['env'];
    $prep = cpr_osub_prepare_p7_baseline($env, $ctx['clone_root']);
    $jid = $prep['job_id'];
    $cprRoot = $prep['cpr_root'];
    $fp = $prep['fp'];
    $run = orange_cpr_owner_submission_live_run($env, cpr_osub_req($jid, $fp));
    cpr_osub('valid_submission_package', !empty($run['ok']), (string) ($run['code'] ?? ''));
    cpr_osub('sealed_flag', !empty($run['sealed']));
    cpr_osub('submission_complete', !empty($run['submission_complete']));
    cpr_osub('lifecycle_submitted', ($run['lifecycle_state'] ?? '') === 'cert_submitted_for_owner');
    cpr_osub('no_owner_decision', ($run['owner_decision_present'] ?? true) === false);
    cpr_osub('no_cert_decision', ($run['is_certification_decision'] ?? true) === false);
    cpr_osub('no_owner_cert_pass', ($run['owner_cert_pass_granted'] ?? true) === false);
    $pkg = is_array($run['package_report'] ?? null) ? $run['package_report'] : null;
    $man = is_array($run['manifest'] ?? null) ? $run['manifest'] : null;
    cpr_osub(
        'sealed_package_and_manifest',
        is_array($pkg) && orange_cpr_auth_verify_seal($pkg)
        && is_array($man) && orange_cpr_auth_verify_seal($man) && !empty($man['sealed'])
    );
    cpr_osub(
        'section_order_frozen',
        is_array($run['section_order'] ?? null)
        && $run['section_order'] === orange_cpr_owner_submission_section_order()
    );
    cpr_osub(
        'fingerprint_present',
        strlen((string) ($run['submission_fingerprint'] ?? '')) === 64
        && strlen((string) ($run['certification_fingerprint'] ?? '')) === 64
    );
    $rec = is_array($run['recovery_metadata'] ?? null) ? $run['recovery_metadata'] : [];
    cpr_osub(
        'recovery_metadata_integrity',
        ($rec['completed_phase'] ?? '') === 'p8_owner_submission_sealed'
        && ($rec['owner_cert_pass_granted'] ?? true) === false
        && ($rec['is_certification_decision'] ?? true) === false
    );
    $auditPath = $cprRoot . DIRECTORY_SEPARATOR . $jid . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME;
    $audit = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
    cpr_osub(
        'audit_integrity',
        str_contains($audit, 'cpr.owner_submission_live_complete')
        && str_contains($audit, '"is_certification_decision":false')
    );
    $idem = orange_cpr_owner_submission_live_run($env, cpr_osub_req($jid, $fp));
    cpr_osub('idempotent_resubmit', !empty($idem['ok']) && !empty($idem['idempotent']));

    // Replay
    $replay = orange_cpr_owner_submission_live_run($env, cpr_osub_req($jid, $fp, ['force_replay' => true]));
    cpr_osub(
        'replay_attempt_refused',
        empty($replay['ok']) && ($replay['code'] ?? '') === ORANGE_CPR_OSUB_ERR_REPLAY
    );

    // Decision knobs refused
    $dec = orange_cpr_owner_submission_live_run($env, cpr_osub_req($jid, $fp, ['owner_cert_pass' => true]));
    cpr_osub(
        'proof_no_certification_decision',
        empty($dec['ok']) && ($dec['code'] ?? '') === ORANGE_CPR_OSUB_ERR_DECISION
    );

    // Missing evidence (valid UUID job with no sealed P7 artifacts)
    $ctx2 = cpr_osub_fresh_env($base);
    $missingJob = '00000000-0000-4000-8000-000000000802';
    $missing = orange_cpr_owner_submission_live_run($ctx2['env'], cpr_osub_req($missingJob, str_repeat('9', 32)));
    cpr_osub('missing_evidence', empty($missing['ok']), (string) ($missing['code'] ?? ''));

    // Corrupt evidence seal
    $ctx3 = cpr_osub_fresh_env($base);
    $prep3 = cpr_osub_prepare_p7_baseline($ctx3['env'], $ctx3['clone_root']);
    $sealPath = orange_cpr_evidence_pack_live_latest_path($prep3['cpr_root'], $prep3['job_id'], 'seal');
    $seal = json_decode((string) file_get_contents($sealPath), true);
    if (is_array($seal)) {
        $seal['pack_seal_hash'] = 'sha256:' . str_repeat('0', 64);
        unset($seal['auth_seal'], $seal['seal'], $seal['_seal']);
        // break seal by rewriting without re-seal
        cpr_osub_rewrite($sealPath, $seal);
    }
    $corrupt = orange_cpr_owner_submission_live_run(
        $ctx3['env'],
        cpr_osub_req($prep3['job_id'], $prep3['fp'])
    );
    cpr_osub(
        'corrupt_evidence',
        empty($corrupt['ok'])
        && in_array(($corrupt['code'] ?? ''), [
            ORANGE_CPR_OSUB_ERR_CORRUPT,
            ORANGE_CPR_OSUB_ERR_MODIFIED,
        ], true),
        (string) ($corrupt['code'] ?? '')
    );

    // Modified pack vs freeze (stale)
    $ctx4 = cpr_osub_fresh_env($base);
    $prep4 = cpr_osub_prepare_p7_baseline($ctx4['env'], $ctx4['clone_root']);
    $packPath = orange_cpr_evidence_pack_live_latest_path($prep4['cpr_root'], $prep4['job_id'], 'pack');
    $pack = orange_cpr_evidence_pack_live_load_latest($prep4['cpr_root'], $prep4['job_id'], 'pack');
    if (is_array($pack)) {
        $pack['pack_seal_hash'] = 'sha256:' . str_repeat('a', 64);
        // re-seal with modified hash so load passes seal verify but continuity fails
        cpr_osub_rewrite($packPath, orange_cpr_auth_seal($pack));
    }
    $modified = orange_cpr_owner_submission_live_run(
        $ctx4['env'],
        cpr_osub_req($prep4['job_id'], $prep4['fp'])
    );
    cpr_osub(
        'modified_evidence',
        empty($modified['ok'])
        && in_array(($modified['code'] ?? ''), [
            ORANGE_CPR_OSUB_ERR_MODIFIED,
            ORANGE_CPR_OSUB_ERR_STALE,
        ], true),
        (string) ($modified['code'] ?? '')
    );

    // Invalid ordering
    $ctx5 = cpr_osub_fresh_env($base);
    $prep5 = cpr_osub_prepare_p7_baseline($ctx5['env'], $ctx5['clone_root']);
    $badOrder = orange_cpr_owner_submission_section_order();
    $tmp = $badOrder[0];
    $badOrder[0] = $badOrder[1];
    $badOrder[1] = $tmp;
    $orderFail = orange_cpr_owner_submission_live_run(
        $ctx5['env'],
        cpr_osub_req($prep5['job_id'], $prep5['fp'], ['section_order' => $badOrder])
    );
    cpr_osub(
        'invalid_ordering',
        empty($orderFail['ok']) && ($orderFail['code'] ?? '') === ORANGE_CPR_OSUB_ERR_ORDER
    );

    // Contract / country / fingerprint mismatch
    $ctx6 = cpr_osub_fresh_env($base);
    $prep6 = cpr_osub_prepare_p7_baseline($ctx6['env'], $ctx6['clone_root']);
    $contract = orange_cpr_contract_read($prep6['cpr_root'], $prep6['job_id']);
    if (is_array($contract)) {
        $contract['package_fingerprint'] = str_repeat('c', 32);
        orange_cpr_contract_write($prep6['cpr_root'], $prep6['job_id'], $contract);
    }
    $contractFail = orange_cpr_owner_submission_live_run(
        $ctx6['env'],
        cpr_osub_req($prep6['job_id'], $prep6['fp'])
    );
    cpr_osub(
        'contract_mismatch',
        empty($contractFail['ok']) && ($contractFail['code'] ?? '') === ORANGE_CPR_OSUB_ERR_CONTRACT,
        (string) ($contractFail['code'] ?? '')
    );

    $ctx6b = cpr_osub_fresh_env($base);
    $prep6b = cpr_osub_prepare_p7_baseline($ctx6b['env'], $ctx6b['clone_root']);
    $countryFail = orange_cpr_owner_submission_live_run(
        $ctx6b['env'],
        cpr_osub_req($prep6b['job_id'], $prep6b['fp'], ['country_id' => 99])
    );
    cpr_osub(
        'country_mismatch',
        empty($countryFail['ok']) && ($countryFail['code'] ?? '') === ORANGE_CPR_OSUB_ERR_COUNTRY
    );
    $fpFail = orange_cpr_owner_submission_live_run(
        $ctx6b['env'],
        cpr_osub_req($prep6b['job_id'], str_repeat('b', 32))
    );
    cpr_osub(
        'fingerprint_mismatch',
        empty($fpFail['ok']) && ($fpFail['code'] ?? '') === ORANGE_CPR_OSUB_ERR_FINGERPRINT
    );

    // Non-SA / bypass
    $priv = orange_cpr_owner_submission_live_run(
        $ctx6b['env'],
        cpr_osub_req($prep6b['job_id'], $prep6b['fp'], [
            'actor_is_super_admin' => false,
            'actor_admin_id' => 3,
        ])
    );
    cpr_osub(
        'no_privilege_bypass',
        empty($priv['ok']) && ($priv['code'] ?? '') === ORANGE_CPR_OSUB_ERR_ACTOR
    );

    $docs = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup';
    cpr_osub(
        'design_doc_exists',
        is_file($docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P8_02_OWNER_SUBMISSION.md')
    );
    $index = (string) file_get_contents($docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P8_ARTIFACT_INDEX.md');
    cpr_osub('index_wp_p8_02_complete', str_contains($index, '**WP-P8-02 COMPLETE**'));
    cpr_osub('index_wp_p8_03_complete', str_contains($index, '**WP-P8-03 COMPLETE**'));
    cpr_osub('index_wp_p8_04_complete', str_contains($index, '**WP-P8-04 COMPLETE**'));
    cpr_osub('index_stop_blocks_p9', str_contains($index, 'Do **not** begin **P9**'));

    $assert = orange_cpr_p8_control_plane_assert(['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false]);
    cpr_osub('control_plane_assert_ok', !empty($assert['ok']), (string) ($assert['code'] ?? ''));
} catch (Throwable $e) {
    cpr_osub('exception', false, $e->getMessage());
} finally {
    cpr_osub_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
