<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Owner Certification Decision (WP-P8-03).
 * Run: php scripts/backup/country_production/self_test_cpr_owner_cert_decision_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_owner_cert_decision_live.php';

$pass = 0;
$fail = 0;

function cpr_ocert(string $name, bool $ok, string $detail = ''): void
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
 * @return array{env:array<string,mixed>,clone_root:string}
 */
function cpr_ocert_fresh_env(string $base): array
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

function cpr_ocert_cleanup(string $base): void
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
function cpr_ocert_rewrite(string $path, array $record): void
{
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('rewrite failed');
    }
    orange_cpr_atomic_rename_replace($tmp, $path);
}

/**
 * @return list<array<string, mixed>>
 */
function cpr_ocert_cg_h_all_accepted(): array
{
    $rows = [];
    foreach (orange_cpr_owner_cert_cg_h_ids() as $gid) {
        $rows[] = [
            'gate_id' => $gid,
            'accepted' => true,
            'notes' => 'Owner accepted ' . $gid,
            'evidence_refs' => ['EV-14'],
        ];
    }

    return $rows;
}

/**
 * @param array<string, mixed> $env
 * @return array{job_id:string,cpr_root:string,fp:string}
 */
function cpr_ocert_prepare_submission(array $env, string $cloneRoot): array
{
    $fp = str_repeat('8', 32);
    $p7 = orange_cpr_p7_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'clone_work_root' => $cloneRoot,
        'package_fingerprint' => $fp,
        'schema_revision' => 121,
        'country_id' => 1,
        'country_code' => 'KW',
        'package_id' => 'drill-pkg-p803',
        'clone_environment_id' => 'clone-env-p803',
        'drill_context' => 'clone',
    ]);
    if (empty($p7['ok'])) {
        throw new RuntimeException('p7 baseline: ' . (string) ($p7['code'] ?? ''));
    }
    $jobId = (string) ($p7['job_id'] ?? '');
    $sub = orange_cpr_owner_submission_live_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => 1,
        'country_code' => 'KW',
        'schema_revision' => 121,
    ]);
    if (empty($sub['ok'])) {
        throw new RuntimeException('submission: ' . (string) ($sub['code'] ?? ''));
    }

    return [
        'job_id' => $jobId,
        'cpr_root' => orange_cpr_resolve_work_root($env),
        'fp' => $fp,
    ];
}

/**
 * @return array<string, mixed>
 */
function cpr_ocert_req(string $jobId, string $fp, string $result, array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 42,
        'actor_is_owner' => true,
        'actor_role' => 'owner',
        'decided_by' => 'owner',
        'owner_certification_ceremony' => true,
        'result' => $result,
        'cg_f01' => $result,
        'cg_h_reviews' => cpr_ocert_cg_h_all_accepted(),
        'rationale' => 'Owner ceremony decision: ' . $result,
        'supporting_evidence_refs' => ['EV-14', 'EV-10'],
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => 1,
        'country_code' => 'KW',
        'schema_revision' => 121,
    ], $extra);
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p803_' . bin2hex(random_bytes(4));
@mkdir($base, 0775, true);

try {
    cpr_ocert('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P9-02-enablement-preconditions');
    cpr_ocert('dirname_constant', ORANGE_CPR_CERTIFICATION_DIRNAME === 'certification');
    cpr_ocert('cg_h_count', count(orange_cpr_owner_cert_cg_h_ids()) === 6);
    cpr_ocert(
        'control_plane_decision_flag',
        !empty(orange_cpr_p8_control_plane_snapshot()['owner_cert_decision_engine_implemented'])
    );
    cpr_ocert(
        'control_plane_freeze_complete_after_p8_04',
        !empty(orange_cpr_p8_control_plane_snapshot()['p8_integration_baseline_complete'])
    );

    $src = (string) file_get_contents(
        dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_owner_cert_decision_live.php'
    );
    cpr_ocert(
        'proof_no_production_sql_in_source',
        !preg_match('/\bdb\s*\(|\bPDO\b|\bmysqli_/i', $src)
        && str_contains($src, "'production_resources_accessed' => false")
    );
    cpr_ocert(
        'proof_pass_does_not_enable_in_source',
        str_contains($src, "'enablement_flag_after_decision' => false")
        && str_contains($src, "'cert_pass_does_not_enable' => true")
    );
    cpr_ocert(
        'proof_fail_no_auto_rollback_in_source',
        str_contains($src, "'auto_rollback_triggered' => false")
        && str_contains($src, 'ORANGE_CPR_OCERT_ERR_ROLLBACK')
    );

    // --- Valid PASS ---
    $ctx = cpr_ocert_fresh_env($base);
    $prep = cpr_ocert_prepare_submission($ctx['env'], $ctx['clone_root']);
    $envBefore = $ctx['env'];
    $runPass = orange_cpr_owner_cert_decision_live_run($envBefore, cpr_ocert_req($prep['job_id'], $prep['fp'], 'PASS'));
    cpr_ocert('valid_pass_decision', !empty($runPass['ok']) && ($runPass['result'] ?? '') === 'PASS', (string) ($runPass['code'] ?? ''));
    cpr_ocert('pass_sealed', !empty($runPass['sealed']) && !empty($runPass['decision_complete']));
    cpr_ocert('pass_lifecycle', ($runPass['lifecycle_state'] ?? '') === 'cert_pass');
    cpr_ocert('pass_decided_by_owner', ($runPass['decided_by'] ?? '') === 'owner');
    cpr_ocert('pass_owner_cert_granted_flag', ($runPass['owner_cert_pass_granted'] ?? false) === true);
    cpr_ocert(
        'proof_pass_does_not_enable_production',
        ($runPass['enablement_flag_after_decision'] ?? true) === false
        && ($runPass['enablement_flag_observed'] ?? true) === false
        && orange_cpr_enablement_flag_read($envBefore) === false
    );
    $cert = is_array($runPass['cpr_certification_result'] ?? null) ? $runPass['cpr_certification_result'] : null;
    $dec = is_array($runPass['decision'] ?? null) ? $runPass['decision'] : null;
    $man = is_array($runPass['manifest'] ?? null) ? $runPass['manifest'] : null;
    cpr_ocert(
        'sealed_artifacts',
        is_array($cert) && orange_cpr_auth_verify_seal($cert)
        && is_array($dec) && orange_cpr_auth_verify_seal($dec)
        && is_array($man) && orange_cpr_auth_verify_seal($man)
        && ($cert['result'] ?? '') === 'PASS'
        && ($cert['schema_version'] ?? '') === 'cpr_certification_result/1'
    );
    cpr_ocert(
        'certification_fingerprint_integrity',
        strlen((string) ($runPass['certification_fingerprint'] ?? '')) === 64
        && strlen((string) ($runPass['decision_fingerprint'] ?? '')) === 64
    );
    $rec = is_array($runPass['recovery_metadata'] ?? null) ? $runPass['recovery_metadata'] : [];
    cpr_ocert(
        'recovery_metadata_integrity',
        ($rec['completed_phase'] ?? '') === 'p8_owner_cert_decision_sealed'
        && ($rec['result'] ?? '') === 'PASS'
        && ($rec['auto_rollback_triggered'] ?? true) === false
        && ($rec['enablement_flag_after_decision'] ?? true) === false
    );
    $auditPath = $prep['cpr_root'] . DIRECTORY_SEPARATOR . $prep['job_id'] . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME;
    $audit = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
    cpr_ocert(
        'audit_integrity',
        str_contains($audit, 'cpr.owner_cert_decision_live_complete')
        && str_contains($audit, '"result":"PASS"')
        && str_contains($audit, '"decided_by":"owner"')
        && str_contains($audit, '"enablement_flag_after_decision":false')
    );

    // Duplicate / replay on same job
    $dup = orange_cpr_owner_cert_decision_live_run($envBefore, cpr_ocert_req($prep['job_id'], $prep['fp'], 'PASS'));
    cpr_ocert(
        'duplicate_certification_attempt',
        empty($dup['ok']) && ($dup['code'] ?? '') === ORANGE_CPR_OCERT_ERR_DUPLICATE,
        (string) ($dup['code'] ?? '')
    );
    $replay = orange_cpr_owner_cert_decision_live_run(
        $envBefore,
        cpr_ocert_req($prep['job_id'], $prep['fp'], 'FAIL', ['force_replay' => true])
    );
    cpr_ocert(
        'replay_attempt',
        empty($replay['ok'])
        && in_array(($replay['code'] ?? ''), [
            ORANGE_CPR_OCERT_ERR_REPLAY,
            ORANGE_CPR_OCERT_ERR_BYPASS,
            ORANGE_CPR_OCERT_ERR_DUPLICATE,
        ], true),
        (string) ($replay['code'] ?? '')
    );

    // --- Valid FAIL ---
    $ctxF = cpr_ocert_fresh_env($base);
    $prepF = cpr_ocert_prepare_submission($ctxF['env'], $ctxF['clone_root']);
    $hFail = cpr_ocert_cg_h_all_accepted();
    $hFail[1]['accepted'] = false;
    $hFail[1]['notes'] = 'Owner declines rollback adequacy';
    $runFail = orange_cpr_owner_cert_decision_live_run(
        $ctxF['env'],
        cpr_ocert_req($prepF['job_id'], $prepF['fp'], 'FAIL', [
            'cg_h_reviews' => $hFail,
            'rationale' => 'Owner FAIL â€” rollback adequacy not accepted.',
        ])
    );
    cpr_ocert('valid_fail_decision', !empty($runFail['ok']) && ($runFail['result'] ?? '') === 'FAIL', (string) ($runFail['code'] ?? ''));
    cpr_ocert('fail_lifecycle', ($runFail['lifecycle_state'] ?? '') === 'cert_fail');
    cpr_ocert(
        'proof_fail_does_not_trigger_automatic_rollback',
        ($runFail['auto_rollback_triggered'] ?? true) === false
        && ($runFail['fail_does_not_auto_rollback'] ?? false) === true
        && !str_contains((string) file_get_contents(
            $prepF['cpr_root'] . DIRECTORY_SEPARATOR . $prepF['job_id'] . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME
        ), '"auto_rollback_triggered":true')
    );
    cpr_ocert(
        'fail_does_not_enable',
        ($runFail['enablement_flag_after_decision'] ?? true) === false
        && ($runFail['owner_cert_pass_granted'] ?? true) === false
    );

    // Auto rollback knob refused
    $ctxRb = cpr_ocert_fresh_env($base);
    $prepRb = cpr_ocert_prepare_submission($ctxRb['env'], $ctxRb['clone_root']);
    $rb = orange_cpr_owner_cert_decision_live_run(
        $ctxRb['env'],
        cpr_ocert_req($prepRb['job_id'], $prepRb['fp'], 'FAIL', ['auto_rollback' => true])
    );
    cpr_ocert(
        'auto_rollback_knob_refused',
        empty($rb['ok']) && ($rb['code'] ?? '') === ORANGE_CPR_OCERT_ERR_ROLLBACK
    );

    // Missing submission
    $ctxM = cpr_ocert_fresh_env($base);
    $missing = orange_cpr_owner_cert_decision_live_run(
        $ctxM['env'],
        cpr_ocert_req('00000000-0000-4000-8000-000000000803', str_repeat('9', 32), 'PASS')
    );
    cpr_ocert('missing_submission_package', empty($missing['ok']), (string) ($missing['code'] ?? ''));

    // Corrupt submission
    $ctxC = cpr_ocert_fresh_env($base);
    $prepC = cpr_ocert_prepare_submission($ctxC['env'], $ctxC['clone_root']);
    $pkgPath = orange_cpr_owner_submission_live_latest_path($prepC['cpr_root'], $prepC['job_id'], 'package');
    $pkg = json_decode((string) file_get_contents($pkgPath), true);
    if (is_array($pkg)) {
        unset($pkg['auth_seal'], $pkg['seal'], $pkg['_seal']);
        $pkg['submission_fingerprint'] = str_repeat('0', 64);
        cpr_ocert_rewrite($pkgPath, $pkg);
    }
    $corrupt = orange_cpr_owner_cert_decision_live_run(
        $ctxC['env'],
        cpr_ocert_req($prepC['job_id'], $prepC['fp'], 'PASS')
    );
    cpr_ocert(
        'corrupt_submission_package',
        empty($corrupt['ok'])
        && in_array(($corrupt['code'] ?? ''), [
            ORANGE_CPR_OCERT_ERR_CORRUPT,
            ORANGE_CPR_OCERT_ERR_MISSING,
            ORANGE_CPR_OCERT_ERR_MODIFIED,
        ], true),
        (string) ($corrupt['code'] ?? '')
    );

    // Contract / country mismatch
    $ctx6 = cpr_ocert_fresh_env($base);
    $prep6 = cpr_ocert_prepare_submission($ctx6['env'], $ctx6['clone_root']);
    $contract = orange_cpr_contract_read($prep6['cpr_root'], $prep6['job_id']);
    if (is_array($contract)) {
        $contract['package_fingerprint'] = str_repeat('c', 32);
        orange_cpr_contract_write($prep6['cpr_root'], $prep6['job_id'], $contract);
    }
    $contractFail = orange_cpr_owner_cert_decision_live_run(
        $ctx6['env'],
        cpr_ocert_req($prep6['job_id'], $prep6['fp'], 'PASS')
    );
    cpr_ocert(
        'contract_mismatch',
        empty($contractFail['ok']) && ($contractFail['code'] ?? '') === ORANGE_CPR_OCERT_ERR_CONTRACT,
        (string) ($contractFail['code'] ?? '')
    );

    $ctx6b = cpr_ocert_fresh_env($base);
    $prep6b = cpr_ocert_prepare_submission($ctx6b['env'], $ctx6b['clone_root']);
    $countryFail = orange_cpr_owner_cert_decision_live_run(
        $ctx6b['env'],
        cpr_ocert_req($prep6b['job_id'], $prep6b['fp'], 'PASS', ['country_id' => 99])
    );
    cpr_ocert(
        'country_mismatch',
        empty($countryFail['ok']) && ($countryFail['code'] ?? '') === ORANGE_CPR_OCERT_ERR_COUNTRY
    );

    // Engineering / privilege bypass
    $eng = orange_cpr_owner_cert_decision_live_run(
        $ctx6b['env'],
        cpr_ocert_req($prep6b['job_id'], $prep6b['fp'], 'PASS', [
            'actor_is_owner' => false,
            'actor_role' => 'engineering',
            'actor_is_engineering' => true,
            'decided_by' => 'engineering',
        ])
    );
    cpr_ocert(
        'no_privilege_bypass_engineering',
        empty($eng['ok'])
        && in_array(($eng['code'] ?? ''), [
            ORANGE_CPR_OCERT_ERR_ENGINEERING,
            ORANGE_CPR_OCERT_ERR_ACTOR,
        ], true),
        (string) ($eng['code'] ?? '')
    );

    // Auto approve refused
    $auto = orange_cpr_owner_cert_decision_live_run(
        $ctx6b['env'],
        cpr_ocert_req($prep6b['job_id'], $prep6b['fp'], 'PASS', ['auto_approve' => true])
    );
    cpr_ocert(
        'no_automatic_approval',
        empty($auto['ok']) && ($auto['code'] ?? '') === ORANGE_CPR_OCERT_ERR_AUTO
    );

    // Ceremony incomplete (PASS without all CG-H)
    $badH = cpr_ocert_cg_h_all_accepted();
    $badH[0]['accepted'] = false;
    $cer = orange_cpr_owner_cert_decision_live_run(
        $ctx6b['env'],
        cpr_ocert_req($prep6b['job_id'], $prep6b['fp'], 'PASS', ['cg_h_reviews' => $badH])
    );
    cpr_ocert(
        'ceremony_required_for_pass',
        empty($cer['ok']) && ($cer['code'] ?? '') === ORANGE_CPR_OCERT_ERR_CEREMONY
    );

    $docs = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup';
    cpr_ocert(
        'design_doc_exists',
        is_file($docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P8_03_OWNER_CERT_DECISION.md')
    );
    $index = (string) file_get_contents($docs . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P8_ARTIFACT_INDEX.md');
    cpr_ocert('index_wp_p8_03_complete', str_contains($index, '**WP-P8-03 COMPLETE**'));
    cpr_ocert('index_wp_p8_04_complete', str_contains($index, '**WP-P8-04 COMPLETE**'));
    cpr_ocert('index_records_enterprise_audit_passed', str_contains($index, '**P8 Enterprise Audit:** **PASSED**'));

    $assert = orange_cpr_p8_control_plane_assert(['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false]);
    cpr_ocert('control_plane_assert_ok', !empty($assert['ok']), (string) ($assert['code'] ?? ''));
} catch (Throwable $e) {
    cpr_ocert('exception', false, $e->getMessage());
} finally {
    cpr_ocert_cleanup($base);
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
