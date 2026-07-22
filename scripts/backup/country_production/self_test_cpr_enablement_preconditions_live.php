<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Enablement Preconditions & Owner Enablement Order (WP-P9-02).
 * Run: php scripts/backup/country_production/self_test_cpr_enablement_preconditions_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_enablement_preconditions_live.php';

$pass = 0;
$fail = 0;

function cpr_epre(string $name, bool $ok, string $detail = ''): void
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
function cpr_epre_fresh_env(string $base): array
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

function cpr_epre_cleanup(string $base): void
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
 * @return list<array<string, mixed>>
 */
function cpr_epre_cg_h_all_accepted(): array
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
 * @return array{job_id:string,cpr_root:string,fp:string,certification_id:string}
 */
function cpr_epre_prepare_cert_pass(array $env, string $cloneRoot): array
{
    $fp = str_repeat('9', 32);
    $p7 = orange_cpr_p7_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'clone_work_root' => $cloneRoot,
        'package_fingerprint' => $fp,
        'schema_revision' => 121,
        'country_id' => 1,
        'country_code' => 'KW',
        'package_id' => 'drill-pkg-p902',
        'clone_environment_id' => 'clone-env-p902',
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
    $cert = orange_cpr_owner_cert_decision_live_run($env, [
        'actor_admin_id' => 42,
        'actor_is_owner' => true,
        'actor_role' => 'owner',
        'decided_by' => 'owner',
        'owner_certification_ceremony' => true,
        'result' => 'PASS',
        'cg_f01' => 'PASS',
        'cg_h_reviews' => cpr_epre_cg_h_all_accepted(),
        'rationale' => 'Owner PASS for P9-02 preconditions tests',
        'supporting_evidence_refs' => ['EV-14'],
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => 1,
        'country_code' => 'KW',
        'schema_revision' => 121,
    ]);
    if (empty($cert['ok'])) {
        throw new RuntimeException('cert pass: ' . (string) ($cert['code'] ?? ''));
    }

    return [
        'job_id' => $jobId,
        'cpr_root' => orange_cpr_resolve_work_root($env),
        'fp' => $fp,
        'certification_id' => (string) ($cert['certification_id'] ?? ''),
    ];
}

/**
 * @return array<string, mixed>
 */
function cpr_epre_req(string $jobId, string $fp, string $certId, array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'actor_role' => 'super_admin',
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => 1,
        'country_code' => 'KW',
        'schema_revision' => 121,
        'implementation_completed' => true,
        'final_enterprise_approval' => true,
        'final_enterprise_approval_id' => 'fea-' . substr($fp, 0, 8),
        'owner_enablement_order' => [
            'order_id' => 'ord-' . substr($fp, 0, 12),
            'issued_by' => 'owner',
            'issued_by_actor_id' => 42,
            'issued_at' => gmdate('c'),
            'schema_revision_bound' => 121,
            'certification_id' => $certId,
            'directive' => ORANGE_CPR_EPRE_DIRECTIVE,
            'sealed' => true,
        ],
    ], $extra);
}

/**
 * @param array<string, mixed> $record
 */
function cpr_epre_rewrite(string $path, array $record): void
{
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('rewrite failed');
    }
    orange_cpr_atomic_rename_replace($tmp, $path);
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p902_' . bin2hex(random_bytes(4));
@mkdir($base, 0775, true);

try {
    cpr_epre('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P9-04-integration-baseline');
    cpr_epre('dirname_constant', ORANGE_CPR_ENABLEMENT_DIRNAME === 'enablement');
    cpr_epre(
        'control_plane_preconditions_flag',
        !empty(orange_cpr_p9_control_plane_snapshot()['enablement_preconditions_engine_implemented'])
    );
    cpr_epre(
        'control_plane_action_engine_after_p9_03',
        !empty(orange_cpr_p9_control_plane_snapshot()['enablement_action_engine_implemented'])
    );
    cpr_epre(
        'control_plane_e5_does_not_enable',
        !empty(orange_cpr_p9_control_plane_snapshot()['e5_does_not_enable'])
    );

    $src = (string) file_get_contents(
        dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_enablement_preconditions_live.php'
    );
    cpr_epre(
        'proof_no_production_sql_in_source',
        !preg_match('/\bdb\s*\(|\bPDO\b|\bmysqli_/i', $src)
        && str_contains($src, "'production_resources_accessed' => false")
    );
    cpr_epre(
        'proof_flag_remains_false_in_source',
        str_contains($src, "'enablement_flag_after_preconditions' => false")
        && str_contains($src, "'ops_flag_flipped_true' => false")
        && str_contains($src, 'ORANGE_CPR_EPRE_ERR_FLAG_WRITE')
    );
    cpr_epre(
        'proof_no_auto_enable_in_source',
        str_contains($src, "'auto_enable_forbidden' => true")
        && str_contains($src, 'ORANGE_CPR_EPRE_ERR_AUTO')
    );

    // --- Valid prerequisite set ---
    $ctx = cpr_epre_fresh_env($base);
    $env = $ctx['env'];
    $prep = cpr_epre_prepare_cert_pass($env, $ctx['clone_root']);
    $run = orange_cpr_enablement_preconditions_live_run(
        $env,
        cpr_epre_req($prep['job_id'], $prep['fp'], $prep['certification_id'])
    );
    cpr_epre('valid_prerequisite_set', !empty($run['ok']), (string) ($run['code'] ?? ''));
    cpr_epre('e5_state', ($run['enablement_state'] ?? '') === ORANGE_CPR_EPRE_STATE_E5);
    cpr_epre('all_preconditions_met', !empty($run['all_preconditions_met']));
    cpr_epre('sealed_flag', !empty($run['sealed']) && !empty($run['preconditions_complete']));
    cpr_epre(
        'proof_enablement_remains_false',
        ($run['enablement_flag_after_preconditions'] ?? true) === false
        && ($run['ops_flag_flipped_true'] ?? true) === false
        && orange_cpr_enablement_flag_read($env) === false
    );
    $pre = is_array($run['preconditions'] ?? null) ? $run['preconditions'] : null;
    $ord = is_array($run['owner_enablement_order'] ?? null) ? $run['owner_enablement_order'] : null;
    $man = is_array($run['manifest'] ?? null) ? $run['manifest'] : null;
    $rep = is_array($run['report'] ?? null) ? $run['report'] : null;
    cpr_epre(
        'sealed_artifacts',
        is_array($pre) && orange_cpr_auth_verify_seal($pre)
        && is_array($ord) && orange_cpr_auth_verify_seal($ord)
        && is_array($man) && orange_cpr_auth_verify_seal($man)
        && is_array($rep) && orange_cpr_auth_verify_seal($rep)
        && ($pre['schema_version'] ?? '') === 'cpr_enablement_preconditions/1'
        && ($ord['schema_version'] ?? '') === 'cpr_owner_enablement_order/1'
        && ($ord['issued_by'] ?? '') === 'owner'
        && ($ord['directive'] ?? '') === ORANGE_CPR_EPRE_DIRECTIVE
    );
    cpr_epre(
        'fingerprint_integrity',
        strlen((string) ($run['preconditions_fingerprint'] ?? '')) === 64
        && strlen((string) ($run['order_fingerprint'] ?? '')) === 64
    );
    $rec = is_array($run['recovery_metadata'] ?? null) ? $run['recovery_metadata'] : [];
    cpr_epre(
        'recovery_metadata_integrity',
        ($rec['completed_phase'] ?? '') === 'p9_enablement_preconditions_sealed'
        && ($rec['enablement_state'] ?? '') === ORANGE_CPR_EPRE_STATE_E5
        && ($rec['enablement_flag_after_preconditions'] ?? true) === false
        && ($rec['state_engine_integrated'] ?? false) === true
        && ($rec['checkpoint_engine_integrated'] ?? false) === true
    );
    $auditPath = $prep['cpr_root'] . DIRECTORY_SEPARATOR . $prep['job_id'] . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME;
    $audit = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
    cpr_epre(
        'audit_integrity',
        str_contains($audit, 'cpr.enablement_preconditions_live_complete')
        && str_contains($audit, '"enablement_state":"E5_preconditions_satisfied"')
        && str_contains($audit, '"enablement_flag_after_preconditions":false')
        && str_contains($audit, '"all_preconditions_met":true')
    );

    // Replay / duplicate
    $dup = orange_cpr_enablement_preconditions_live_run(
        $env,
        cpr_epre_req($prep['job_id'], $prep['fp'], $prep['certification_id'])
    );
    cpr_epre(
        'replay_attempt',
        empty($dup['ok'])
        && in_array(($dup['code'] ?? ''), [
            ORANGE_CPR_EPRE_ERR_DUPLICATE,
            ORANGE_CPR_EPRE_ERR_REPLAY,
        ], true),
        (string) ($dup['code'] ?? '')
    );

    // Missing prerequisite (no enterprise approval)
    $ctxM = cpr_epre_fresh_env($base);
    $prepM = cpr_epre_prepare_cert_pass($ctxM['env'], $ctxM['clone_root']);
    $miss = orange_cpr_enablement_preconditions_live_run(
        $ctxM['env'],
        cpr_epre_req($prepM['job_id'], $prepM['fp'], $prepM['certification_id'], [
            'final_enterprise_approval' => false,
            'final_enterprise_approval_id' => '',
        ])
    );
    cpr_epre(
        'missing_prerequisite',
        empty($miss['ok']) && ($miss['code'] ?? '') === ORANGE_CPR_EPRE_ERR_MISSING,
        (string) ($miss['code'] ?? '')
    );

    // Corrupt prerequisite (break cert seal)
    $ctxC = cpr_epre_fresh_env($base);
    $prepC = cpr_epre_prepare_cert_pass($ctxC['env'], $ctxC['clone_root']);
    $certPath = orange_cpr_owner_cert_decision_live_latest_path($prepC['cpr_root'], $prepC['job_id'], 'result');
    $certRec = json_decode((string) file_get_contents($certPath), true);
    if (!is_array($certRec)) {
        throw new RuntimeException('cert load for corrupt test');
    }
    $certRec['result'] = 'PASS';
    $certRec['tampered'] = true;
    unset($certRec['seal'], $certRec['seal_alg'], $certRec['sealed_payload_hash']);
    cpr_epre_rewrite($certPath, $certRec);
    $corrupt = orange_cpr_enablement_preconditions_live_run(
        $ctxC['env'],
        cpr_epre_req($prepC['job_id'], $prepC['fp'], $prepC['certification_id'])
    );
    cpr_epre(
        'corrupt_prerequisite',
        empty($corrupt['ok'])
        && in_array(($corrupt['code'] ?? ''), [
            ORANGE_CPR_EPRE_ERR_CORRUPT,
            ORANGE_CPR_EPRE_ERR_MISSING,
        ], true),
        (string) ($corrupt['code'] ?? '')
    );

    // Owner Certification mismatch (order cert id wrong)
    $ctxCert = cpr_epre_fresh_env($base);
    $prepCert = cpr_epre_prepare_cert_pass($ctxCert['env'], $ctxCert['clone_root']);
    $badCert = orange_cpr_enablement_preconditions_live_run(
        $ctxCert['env'],
        cpr_epre_req($prepCert['job_id'], $prepCert['fp'], $prepCert['certification_id'], [
            'owner_enablement_order' => [
                'order_id' => 'ord-badcert',
                'issued_by' => 'owner',
                'issued_by_actor_id' => 42,
                'issued_at' => gmdate('c'),
                'schema_revision_bound' => 121,
                'certification_id' => 'not-the-sealed-cert-id',
                'directive' => ORANGE_CPR_EPRE_DIRECTIVE,
                'sealed' => true,
            ],
        ])
    );
    cpr_epre(
        'owner_certification_mismatch',
        empty($badCert['ok']) && ($badCert['code'] ?? '') === ORANGE_CPR_EPRE_ERR_CERT,
        (string) ($badCert['code'] ?? '')
    );

    // Schema mismatch
    $ctxS = cpr_epre_fresh_env($base);
    $prepS = cpr_epre_prepare_cert_pass($ctxS['env'], $ctxS['clone_root']);
    $badSchema = orange_cpr_enablement_preconditions_live_run(
        $ctxS['env'],
        cpr_epre_req($prepS['job_id'], $prepS['fp'], $prepS['certification_id'], [
            'schema_revision' => 999,
        ])
    );
    cpr_epre(
        'schema_mismatch',
        empty($badSchema['ok']) && ($badSchema['code'] ?? '') === ORANGE_CPR_EPRE_ERR_SCHEMA,
        (string) ($badSchema['code'] ?? '')
    );

    // Permission mismatch (Country Admin)
    $ctxP = cpr_epre_fresh_env($base);
    $prepP = cpr_epre_prepare_cert_pass($ctxP['env'], $ctxP['clone_root']);
    $badPerm = orange_cpr_enablement_preconditions_live_run(
        $ctxP['env'],
        cpr_epre_req($prepP['job_id'], $prepP['fp'], $prepP['certification_id'], [
            'actor_is_super_admin' => false,
            'actor_is_country_admin' => true,
            'actor_role' => 'country_admin',
        ])
    );
    cpr_epre(
        'permission_mismatch',
        empty($badPerm['ok']) && ($badPerm['code'] ?? '') === ORANGE_CPR_EPRE_ERR_PERMISSION,
        (string) ($badPerm['code'] ?? '')
    );

    // Flag write / enable attempt refused
    $ctxE = cpr_epre_fresh_env($base);
    $prepE = cpr_epre_prepare_cert_pass($ctxE['env'], $ctxE['clone_root']);
    $flagWrite = orange_cpr_enablement_preconditions_live_run(
        $ctxE['env'],
        cpr_epre_req($prepE['job_id'], $prepE['fp'], $prepE['certification_id'], [
            'enablement_true' => true,
        ])
    );
    cpr_epre(
        'flag_write_refused',
        empty($flagWrite['ok']) && ($flagWrite['code'] ?? '') === ORANGE_CPR_EPRE_ERR_FLAG_WRITE,
        (string) ($flagWrite['code'] ?? '')
    );
    cpr_epre('ops_flag_still_false_after_refusals', orange_cpr_enablement_flag_read($ctxE['env']) === false);

    $assert = orange_cpr_p9_control_plane_assert(['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false]);
    cpr_epre('control_plane_assert_ok', !empty($assert['ok']), (string) ($assert['code'] ?? ''));

    $docsRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup';
    $design = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P9_02_ENABLEMENT_PRECONDITIONS.md';
    $index = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P9_ARTIFACT_INDEX.md';
    cpr_epre('design_doc_exists', is_file($design));
    $indexBody = is_file($index) ? (string) file_get_contents($index) : '';
    cpr_epre('index_wp_p9_02_complete', str_contains($indexBody, '**WP-P9-02 COMPLETE**'));
    cpr_epre('index_wp_p9_03_complete_after_p9_03', str_contains($indexBody, '**WP-P9-03 COMPLETE**'));
    cpr_epre('index_wp_p9_04_complete_after_p9_04', str_contains($indexBody, '**WP-P9-04 COMPLETE**'));
} catch (Throwable $e) {
    cpr_epre('exception', false, $e->getMessage());
}

cpr_epre_cleanup($base);

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
