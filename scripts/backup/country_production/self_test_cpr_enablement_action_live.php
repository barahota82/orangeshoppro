<?php

declare(strict_types=1);

/**
 * Self-test: CPR Live Super Admin Enable/Disable & Schema Force-Disable (WP-P9-03).
 * Run: php scripts/backup/country_production/self_test_cpr_enablement_action_live.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_enablement_action_live.php';

$pass = 0;
$fail = 0;

function cpr_eact(string $name, bool $ok, string $detail = ''): void
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
function cpr_eact_fresh_env(string $base): array
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

function cpr_eact_cleanup(string $base): void
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
function cpr_eact_cg_h_all_accepted(): array
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
function cpr_eact_prepare_e5(array $env, string $cloneRoot): array
{
    $fp = str_repeat('a', 32);
    $p7 = orange_cpr_p7_integration_run($env, [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'clone_work_root' => $cloneRoot,
        'package_fingerprint' => $fp,
        'schema_revision' => 121,
        'country_id' => 1,
        'country_code' => 'KW',
        'package_id' => 'drill-pkg-p903',
        'clone_environment_id' => 'clone-env-p903',
        'drill_context' => 'clone',
    ]);
    if (empty($p7['ok'])) {
        throw new RuntimeException('p7: ' . (string) ($p7['code'] ?? ''));
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
        'cg_h_reviews' => cpr_eact_cg_h_all_accepted(),
        'rationale' => 'Owner PASS for P9-03',
        'supporting_evidence_refs' => ['EV-14'],
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => 1,
        'country_code' => 'KW',
        'schema_revision' => 121,
    ]);
    if (empty($cert['ok'])) {
        throw new RuntimeException('cert: ' . (string) ($cert['code'] ?? ''));
    }
    $certId = (string) ($cert['certification_id'] ?? '');
    $e5 = orange_cpr_enablement_preconditions_live_run($env, [
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
        'final_enterprise_approval_id' => 'fea-p903',
        'owner_enablement_order' => [
            'order_id' => 'ord-p903-' . substr($fp, 0, 8),
            'issued_by' => 'owner',
            'issued_by_actor_id' => 42,
            'issued_at' => gmdate('c'),
            'schema_revision_bound' => 121,
            'certification_id' => $certId,
            'directive' => ORANGE_CPR_EPRE_DIRECTIVE,
            'sealed' => true,
        ],
    ]);
    if (empty($e5['ok'])) {
        throw new RuntimeException('e5: ' . (string) ($e5['code'] ?? ''));
    }

    return [
        'job_id' => $jobId,
        'cpr_root' => orange_cpr_resolve_work_root($env),
        'fp' => $fp,
        'certification_id' => $certId,
    ];
}

/**
 * @return array<string, mixed>
 */
function cpr_eact_req(string $jobId, string $fp, string $action, array $extra = []): array
{
    return array_merge([
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'actor_role' => 'super_admin',
        'action' => $action,
        'automatic' => false,
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => 1,
        'country_code' => 'KW',
        'schema_revision' => 121,
    ], $extra);
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_cpr_p903_' . bin2hex(random_bytes(4));
@mkdir($base, 0775, true);

try {
    cpr_eact('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P9-03-enablement-actions');
    cpr_eact(
        'control_plane_action_flag',
        !empty(orange_cpr_p9_control_plane_snapshot()['enablement_action_engine_implemented'])
    );
    cpr_eact(
        'control_plane_flag_write_authorized',
        !empty(orange_cpr_p9_control_plane_snapshot()['enablement_flag_write_authorized'])
    );
    cpr_eact(
        'control_plane_only_wp_p9_03',
        !empty(orange_cpr_p9_control_plane_snapshot()['only_wp_p9_03_may_change_flag'])
    );

    $src = (string) file_get_contents(
        dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_enablement_action_live.php'
    );
    $srcEn = (string) file_get_contents(
        dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_enablement.php'
    );
    cpr_eact(
        'proof_no_production_sql_in_source',
        !preg_match('/\bdb\s*\(|\bPDO\b|\bmysqli_/i', $src)
        && str_contains($src, "'production_sql_executed' => false")
    );
    cpr_eact(
        'proof_only_this_wp_changes_flag',
        str_contains($src, "'written_by_wp' => 'WP-P9-03'")
        && str_contains($srcEn, "'written_by_wp' => 'WP-P9-03'")
        && str_contains($srcEn, 'function orange_cpr_enablement_ops_state_write')
        && !str_contains(
            (string) file_get_contents(
                dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_enablement_preconditions_live.php'
            ),
            'orange_cpr_enablement_ops_state_write'
        )
    );
    cpr_eact(
        'proof_no_auto_reenable_in_source',
        str_contains($src, "'auto_reenable' => false")
        && str_contains($src, 'ORANGE_CPR_EACT_ERR_AUTO')
    );

    // --- Valid Enable ---
    $ctx = cpr_eact_fresh_env($base);
    $env = $ctx['env'];
    $prep = cpr_eact_prepare_e5($env, $ctx['clone_root']);
    cpr_eact('pre_enable_flag_false', orange_cpr_enablement_flag_read($env) === false);
    $en = orange_cpr_enablement_action_live_run($env, cpr_eact_req($prep['job_id'], $prep['fp'], 'enable'));
    cpr_eact('valid_enable', !empty($en['ok']) && ($en['action_kind'] ?? '') === 'enable', (string) ($en['code'] ?? ''));
    cpr_eact('enable_state_e6', ($en['enablement_state'] ?? '') === ORANGE_CPR_EACT_STATE_E6);
    cpr_eact('enable_flag_true', ($en['flag_after'] ?? false) === true && orange_cpr_enablement_flag_read($env) === true);
    cpr_eact(
        'enable_sealed_decision',
        is_array($en['decision'] ?? null) && orange_cpr_auth_verify_seal($en['decision'])
        && is_array($en['manifest'] ?? null) && orange_cpr_auth_verify_seal($en['manifest'])
    );
    $rec = is_array($en['recovery_metadata'] ?? null) ? $en['recovery_metadata'] : [];
    cpr_eact(
        'recovery_metadata_integrity',
        ($rec['completed_phase'] ?? '') === 'p9_enablement_action_sealed'
        && ($rec['enablement_state'] ?? '') === ORANGE_CPR_EACT_STATE_E6
        && ($rec['written_by_wp'] ?? '') === 'WP-P9-03'
        && ($rec['automatic'] ?? true) === false
    );
    $auditPath = $prep['cpr_root'] . DIRECTORY_SEPARATOR . $prep['job_id'] . DIRECTORY_SEPARATOR . ORANGE_CPR_AUDIT_FILENAME;
    $audit = is_file($auditPath) ? (string) file_get_contents($auditPath) : '';
    cpr_eact(
        'audit_integrity',
        str_contains($audit, 'cpr.enablement_action_live_complete')
        && str_contains($audit, '"audit_event":"cpr.enable"')
        && str_contains($audit, '"flag_after":true')
        && str_contains($audit, '"written_by_wp":"WP-P9-03"')
    );

    // Replay enable
    $replay = orange_cpr_enablement_action_live_run($env, cpr_eact_req($prep['job_id'], $prep['fp'], 'enable'));
    cpr_eact(
        'replay_attempt',
        empty($replay['ok'])
        && in_array(($replay['code'] ?? ''), [ORANGE_CPR_EACT_ERR_STATE, ORANGE_CPR_EACT_ERR_REPLAY], true),
        (string) ($replay['code'] ?? '')
    );

    // Valid Disable
    $dis = orange_cpr_enablement_action_live_run($env, cpr_eact_req($prep['job_id'], $prep['fp'], 'disable'));
    cpr_eact('valid_disable', !empty($dis['ok']) && ($dis['action_kind'] ?? '') === 'disable', (string) ($dis['code'] ?? ''));
    cpr_eact('disable_state_e7', ($dis['enablement_state'] ?? '') === ORANGE_CPR_EACT_STATE_E7);
    cpr_eact('disable_flag_false', ($dis['flag_after'] ?? true) === false && orange_cpr_enablement_flag_read($env) === false);

    // Schema force-disable (re-enable first)
    $en2 = orange_cpr_enablement_action_live_run($env, cpr_eact_req($prep['job_id'], $prep['fp'], 'enable'));
    cpr_eact('reenable_after_disable', !empty($en2['ok']) && orange_cpr_enablement_flag_read($env) === true, (string) ($en2['code'] ?? ''));
    $fd = orange_cpr_enablement_action_live_run(
        $env,
        cpr_eact_req($prep['job_id'], $prep['fp'], 'schema_force_disable', [
            'schema_revision_current' => 999,
        ])
    );
    cpr_eact('schema_force_disable', !empty($fd['ok']) && ($fd['action_kind'] ?? '') === 'schema_force_disable', (string) ($fd['code'] ?? ''));
    cpr_eact('force_disable_state_e8', ($fd['enablement_state'] ?? '') === ORANGE_CPR_EACT_STATE_E8);
    cpr_eact('force_disable_flag_false', orange_cpr_enablement_flag_read($env) === false);
    $inv = is_array($fd['schema_invalidation'] ?? null) ? $fd['schema_invalidation'] : null;
    cpr_eact(
        'schema_invalidation_event',
        is_array($inv)
        && orange_cpr_auth_verify_seal($inv)
        && ($inv['prior_certification_invalidated'] ?? false) === true
        && ($inv['auto_reenable'] ?? true) === false
        && ($inv['flag_forced_false'] ?? false) === true
    );
    $noRe = orange_cpr_enablement_action_live_run($env, cpr_eact_req($prep['job_id'], $prep['fp'], 'enable'));
    cpr_eact(
        'no_auto_reenable_after_e8',
        empty($noRe['ok']) && ($noRe['code'] ?? '') === ORANGE_CPR_EACT_ERR_STATE,
        (string) ($noRe['code'] ?? '')
    );

    // Missing prerequisites (no E5)
    $ctxM = cpr_eact_fresh_env($base);
    $fpM = str_repeat('b', 32);
    $p7m = orange_cpr_p7_integration_run($ctxM['env'], [
        'actor_admin_id' => 7,
        'actor_is_super_admin' => true,
        'clone_work_root' => $ctxM['clone_root'],
        'package_fingerprint' => $fpM,
        'schema_revision' => 121,
        'country_id' => 1,
        'country_code' => 'KW',
        'package_id' => 'drill-pkg-p903m',
        'clone_environment_id' => 'clone-env-p903m',
        'drill_context' => 'clone',
    ]);
    $miss = orange_cpr_enablement_action_live_run(
        $ctxM['env'],
        cpr_eact_req((string) ($p7m['job_id'] ?? ''), $fpM, 'enable')
    );
    cpr_eact(
        'missing_prerequisites',
        empty($miss['ok']) && ($miss['code'] ?? '') === ORANGE_CPR_EACT_ERR_MISSING,
        (string) ($miss['code'] ?? '')
    );

    // Permission failure
    $ctxP = cpr_eact_fresh_env($base);
    $prepP = cpr_eact_prepare_e5($ctxP['env'], $ctxP['clone_root']);
    $perm = orange_cpr_enablement_action_live_run(
        $ctxP['env'],
        cpr_eact_req($prepP['job_id'], $prepP['fp'], 'enable', [
            'actor_is_super_admin' => false,
            'actor_is_country_admin' => true,
            'actor_role' => 'country_admin',
        ])
    );
    cpr_eact(
        'permission_failure',
        empty($perm['ok']) && ($perm['code'] ?? '') === ORANGE_CPR_EACT_ERR_PERMISSION,
        (string) ($perm['code'] ?? '')
    );

    // Owner approval missing (corrupt order issued_by)
    $ctxO = cpr_eact_fresh_env($base);
    $prepO = cpr_eact_prepare_e5($ctxO['env'], $ctxO['clone_root']);
    $orderPath = orange_cpr_enablement_preconditions_live_latest_path($prepO['cpr_root'], $prepO['job_id'], 'order');
    $orderRec = json_decode((string) file_get_contents($orderPath), true);
    if (!is_array($orderRec)) {
        throw new RuntimeException('order load');
    }
    $orderRec['issued_by'] = 'engineering';
    unset($orderRec['seal'], $orderRec['seal_alg'], $orderRec['sealed_payload_hash']);
    $tmp = $orderPath . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($orderRec, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    orange_cpr_atomic_rename_replace($tmp, $orderPath);
    // Re-seal would be needed for load_latest to accept — without seal, load returns null → owner missing
    $own = orange_cpr_enablement_action_live_run($ctxO['env'], cpr_eact_req($prepO['job_id'], $prepO['fp'], 'enable'));
    cpr_eact(
        'owner_approval_missing',
        empty($own['ok'])
        && in_array(($own['code'] ?? ''), [ORANGE_CPR_EACT_ERR_OWNER, ORANGE_CPR_EACT_ERR_MISSING], true),
        (string) ($own['code'] ?? '')
    );

    $assert = orange_cpr_p9_control_plane_assert(['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false]);
    cpr_eact('control_plane_assert_ok', !empty($assert['ok']), (string) ($assert['code'] ?? ''));

    $docsRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup';
    $index = is_file($docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P9_ARTIFACT_INDEX.md')
        ? (string) file_get_contents($docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P9_ARTIFACT_INDEX.md')
        : '';
    cpr_eact(
        'design_doc_exists',
        is_file($docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P9_03_ENABLEMENT_ACTIONS.md')
    );
    cpr_eact('index_wp_p9_03_complete', str_contains($index, '**WP-P9-03 COMPLETE**'));
    cpr_eact('index_stop_blocks_wp04', str_contains($index, 'Do **not** begin **WP-P9-04**'));
} catch (Throwable $e) {
    cpr_eact('exception', false, $e->getMessage());
}

cpr_eact_cleanup($base);

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
