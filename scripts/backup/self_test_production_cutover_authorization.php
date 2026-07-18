<?php

declare(strict_types=1);

/**
 * P0-3 / 3B.4K — Explicit production cutover authorization self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_production_cutover_authorization.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);

// Load shared production-import fixture helpers without running that suite.
require_once $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'self_test_production_import.php';

$passes = 0;
$failures = 0;
$tmpRoot = '';

/**
 * @param mixed $cond
 */
function pca_self_test(bool $cond, string $label): void
{
    global $passes, $failures;
    if ($cond) {
        echo 'PASS: ' . $label . PHP_EOL;
        $passes++;
    } else {
        echo 'FAIL: ' . $label . PHP_EOL;
        $failures++;
    }
}

echo "=== self_test_production_cutover_authorization (P0-3) ===\n";

try {
    global $fixtureProjectRoot;

    $tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_pca_' . bin2hex(random_bytes(4));
    $backupRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'backup';
    $workRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'work';
    mkdir($backupRoot . DIRECTORY_SEPARATOR . 'snapshots', 0775, true);
    mkdir($workRoot, 0775, true);

    $pkgId = '2026-07-17_130000';
    $pkgDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgId;
    pi_seed_package($pkgDir, $pkgId);

    // API surface: challenge / finalize / status — metadata only.
    $apiDir = $projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api'
        . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR;
    $challengeApi = (string) file_get_contents($apiDir . 'create-cutover-authorization-challenge.php');
    $finalizeApi = (string) file_get_contents($apiDir . 'finalize-cutover-authorization.php');
    $statusApi = (string) file_get_contents($apiDir . 'cutover-authorization.php');
    pca_self_test(
        str_contains($challengeApi, 'restore_admin_api_require_csrf')
        && str_contains($challengeApi, 'http_never_imports')
        && str_contains($challengeApi, 'execution_started')
        && !str_contains($challengeApi, 'orange_restore_prod_import_run_cli'),
        'API: challenge CSRF + never imports'
    );
    pca_self_test(
        str_contains($finalizeApi, 'restore_admin_api_require_csrf')
        && str_contains($finalizeApi, 'http_never_imports')
        && !str_contains($finalizeApi, 'orange_restore_prod_import_run_cli')
        && !str_contains($finalizeApi, 'production_wipe'),
        'API: finalize CSRF + never imports/wipes'
    );
    pca_self_test(
        str_contains($statusApi, 'restore_admin_api_require_get')
        && str_contains($statusApi, 'read_only'),
        'API: status is GET read-only'
    );

    $mod = (string) file_get_contents(
        $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
        . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_cutover_authorization.php'
    );
    pca_self_test(
        str_contains($mod, 'production_cutover_authorization.json')
        && str_contains($mod, 'authorization_consumed')
        && str_contains($mod, 'cutover_started')
        && !str_contains($mod, 'orange_restore_production_wipe'),
        'module: authorization object fields; no wipe'
    );

    // Happy path: challenge → finalize → import gate ok.
    $seed = pi_seed_ready_job($workRoot, $backupRoot, $pkgId);
    $jobId = $seed['job_id'];
    $req = orange_restore_prod_maint_request($workRoot, $jobId, $backupRoot, $seed['admin']);
    orange_restore_prod_maint_activate(
        $workRoot,
        $jobId,
        $backupRoot,
        $seed['admin'],
        $seed['pdo'],
        'restore-test-password',
        (string) ($req['challenge']['nonce'] ?? '')
    );

    $gateBefore = orange_restore_pca_validate_for_import($workRoot, $jobId, $backupRoot);
    pca_self_test(
        ($gateBefore['ok'] ?? true) === false
        && ($gateBefore['code'] ?? '') === 'production_cutover_authorization_required',
        'gate: import blocked without authorization'
    );

    $challenge = orange_restore_pca_create_challenge(
        $workRoot,
        $jobId,
        $backupRoot,
        $seed['admin'],
        $seed['pdo']
    );
    pca_self_test(($challenge['nonce'] ?? '') !== '', 'challenge: nonce issued');
    pca_self_test(($challenge['cutover_started'] ?? true) === false, 'challenge: cutover_started false');

    $granted = orange_restore_pca_finalize(
        $workRoot,
        $jobId,
        $backupRoot,
        $seed['admin'],
        $seed['pdo'],
        $pkgId,
        (string) ($challenge['required_confirmation_phrase'] ?? ''),
        (string) ($challenge['nonce'] ?? ''),
        'restore-test-password',
        'Owner explicitly authorizes irreversible production cutover window'
    );
    $auth = $granted['authorization'] ?? [];
    pca_self_test(is_file(orange_restore_pca_record_path($workRoot, $jobId)), 'finalize: record written');
    pca_self_test(($auth['authorization_consumed'] ?? true) === false, 'finalize: not consumed');
    pca_self_test(($auth['cutover_started'] ?? true) === false, 'finalize: cutover_started false');
    pca_self_test(
        (string) ($auth['authorization_version'] ?? '') === ORANGE_RESTORE_PCA_VERSION
        && (string) ($auth['job_id'] ?? '') === $jobId
        && (string) ($auth['package_id'] ?? '') === $pkgId
        && (string) ($auth['package_fingerprint'] ?? '') !== ''
        && (string) ($auth['execution_contract_hash'] ?? '') !== ''
        && (string) ($auth['rollback_anchor_hash'] ?? '') !== ''
        && (string) ($auth['approval_hash'] ?? '') !== ''
        && (string) ($auth['authorized_by'] ?? '') !== ''
        && (string) ($auth['authorized_at'] ?? '') !== ''
        && (string) ($auth['authorization_reason'] ?? '') !== ''
        && (string) ($auth['owner_confirmation_phrase_hash'] ?? '') !== ''
        && (string) ($auth['authorization_nonce_hash'] ?? '') !== ''
        && (string) ($auth['authorization_expires_at'] ?? '') !== '',
        'finalize: required authorization fields present'
    );

    $gateOk = orange_restore_pca_validate_for_import($workRoot, $jobId, $backupRoot);
    pca_self_test(($gateOk['ok'] ?? false) === true, 'gate: import allowed after authorization');

    $status = orange_restore_pca_status($workRoot, $jobId, $backupRoot);
    pca_self_test(($status['present'] ?? false) === true && ($status['import_gate_ok'] ?? false) === true, 'status: present + gate ok');

    // Replay: same nonce rejected.
    $replayBlocked = false;
    try {
        orange_restore_pca_finalize(
            $workRoot,
            $jobId,
            $backupRoot,
            $seed['admin'],
            $seed['pdo'],
            $pkgId,
            (string) ($challenge['required_confirmation_phrase'] ?? ''),
            (string) ($challenge['nonce'] ?? ''),
            'restore-test-password',
            'Owner explicitly authorizes irreversible production cutover window'
        );
    } catch (Throwable $e) {
        $replayBlocked = in_array(trim($e->getMessage()), [
            'authorization_challenge_replay',
            'authorization_already_active',
            'authorization_challenge_missing',
        ], true);
    }
    pca_self_test($replayBlocked, 'replay: finalize with consumed challenge rejected');

    // Expiry of authorization window.
    $rec = orange_restore_pca_load_record($workRoot, $jobId);
    $rec['authorization_expires_at'] = gmdate('c', time() - 10);
    orange_restore_pca_write_record($workRoot, $jobId, $rec);
    $expiredGate = orange_restore_pca_validate_for_import($workRoot, $jobId, $backupRoot);
    pca_self_test(($expiredGate['code'] ?? '') === 'authorization_expired', 'expiry: import gate rejects expired authorization');

    // Fresh maintenance job without prior authorization for negative finalize cases.
    pi_retire_job($workRoot, $jobId);
    $seed3 = pi_seed_ready_job($workRoot, $backupRoot, $pkgId);
    $job3 = $seed3['job_id'];
    $req3 = orange_restore_prod_maint_request($workRoot, $job3, $backupRoot, $seed3['admin']);
    orange_restore_prod_maint_activate(
        $workRoot,
        $job3,
        $backupRoot,
        $seed3['admin'],
        $seed3['pdo'],
        'restore-test-password',
        (string) ($req3['challenge']['nonce'] ?? '')
    );
    $ch3 = orange_restore_pca_create_challenge($workRoot, $job3, $backupRoot, $seed3['admin'], $seed3['pdo']);

    $wrongPkg = false;
    try {
        orange_restore_pca_finalize(
            $workRoot,
            $job3,
            $backupRoot,
            $seed3['admin'],
            $seed3['pdo'],
            'WRONG_PACKAGE',
            (string) ($ch3['required_confirmation_phrase'] ?? ''),
            (string) ($ch3['nonce'] ?? ''),
            'restore-test-password',
            'Owner explicitly authorizes irreversible production cutover window'
        );
    } catch (Throwable $e) {
        $wrongPkg = trim($e->getMessage()) === 'authorization_wrong_package';
    }
    pca_self_test($wrongPkg, 'wrong package rejected');

    // Wrong job: finalize job4 using nonce from job3 challenge.
    // Keep job3 retired first so execution lock is free for the next fixture job.
    $ch3Nonce = (string) ($ch3['nonce'] ?? '');
    pi_retire_job($workRoot, $job3);
    $seed4 = pi_seed_ready_job($workRoot, $backupRoot, $pkgId);
    $job4 = $seed4['job_id'];
    $req4 = orange_restore_prod_maint_request($workRoot, $job4, $backupRoot, $seed4['admin']);
    orange_restore_prod_maint_activate(
        $workRoot,
        $job4,
        $backupRoot,
        $seed4['admin'],
        $seed4['pdo'],
        'restore-test-password',
        (string) ($req4['challenge']['nonce'] ?? '')
    );
    $wrongJob = false;
    try {
        orange_restore_pca_finalize(
            $workRoot,
            $job4,
            $backupRoot,
            $seed4['admin'],
            $seed4['pdo'],
            $pkgId,
            orange_restore_pca_phrase($pkgId, $job4),
            $ch3Nonce,
            'restore-test-password',
            'Owner explicitly authorizes irreversible production cutover window'
        );
    } catch (Throwable $e) {
        $wrongJob = in_array(trim($e->getMessage()), [
            'authorization_challenge_missing',
            'authorization_nonce_invalid',
            'authorization_wrong_job',
        ], true);
    }
    pca_self_test($wrongJob, 'wrong job / missing challenge rejected');

    // Changed fingerprint after challenge (reuse job4).
    $chFp = orange_restore_pca_create_challenge($workRoot, $job4, $backupRoot, $seed4['admin'], $seed4['pdo']);
    $challengePath = orange_restore_pca_challenge_path($workRoot, $job4);
    $chBody = json_decode((string) file_get_contents($challengePath), true);
    $chBody['package_fingerprint'] = hash('sha256', 'tampered-package');
    file_put_contents($challengePath, json_encode($chBody, JSON_PRETTY_PRINT) . "\n");
    $fpChanged = false;
    try {
        orange_restore_pca_finalize(
            $workRoot,
            $job4,
            $backupRoot,
            $seed4['admin'],
            $seed4['pdo'],
            $pkgId,
            (string) ($chFp['required_confirmation_phrase'] ?? ''),
            (string) ($chFp['nonce'] ?? ''),
            'restore-test-password',
            'Owner explicitly authorizes irreversible production cutover window'
        );
    } catch (Throwable $e) {
        $fpChanged = trim($e->getMessage()) === 'authorization_package_fingerprint_changed';
    }
    pca_self_test($fpChanged, 'changed package fingerprint rejected');

    // Changed rollback anchor after valid auth.
    pi_authorize_production_cutover($workRoot, $backupRoot, $job4, $seed4['admin'], $seed4['pdo'], $pkgId);
    $rec4 = orange_restore_pca_load_record($workRoot, $job4);
    $rec4['rollback_anchor_hash'] = hash('sha256', 'tampered-anchor');
    orange_restore_pca_write_record($workRoot, $job4, $rec4);
    $anchorGate = orange_restore_pca_validate_for_import($workRoot, $job4, $backupRoot);
    pca_self_test(($anchorGate['code'] ?? '') === 'authorization_rollback_anchor_changed', 'changed rollback anchor rejected');

    // Changed approval hash.
    $rec4 = orange_restore_pca_load_record($workRoot, $job4);
    $live = orange_restore_pca_live_bindings($workRoot, $job4, $backupRoot);
    $rec4['rollback_anchor_hash'] = (string) (($live['bindings']['rollback_anchor_hash'] ?? ''));
    $rec4['approval_hash'] = hash('sha256', 'tampered-approval');
    $rec4['authorization_expires_at'] = gmdate('c', time() + 600);
    orange_restore_pca_write_record($workRoot, $job4, $rec4);
    $approvalGate = orange_restore_pca_validate_for_import($workRoot, $job4, $backupRoot);
    pca_self_test(($approvalGate['code'] ?? '') === 'authorization_approval_changed', 'changed approval rejected');

    // Wrong operator.
    pi_retire_job($workRoot, $job4);
    $seed5 = pi_seed_ready_job($workRoot, $backupRoot, $pkgId);
    $job5 = $seed5['job_id'];
    $req5 = orange_restore_prod_maint_request($workRoot, $job5, $backupRoot, $seed5['admin']);
    orange_restore_prod_maint_activate(
        $workRoot,
        $job5,
        $backupRoot,
        $seed5['admin'],
        $seed5['pdo'],
        'restore-test-password',
        (string) ($req5['challenge']['nonce'] ?? '')
    );
    $ch5 = orange_restore_pca_create_challenge($workRoot, $job5, $backupRoot, $seed5['admin'], $seed5['pdo']);
    $otherAdmin = $seed5['admin'];
    $otherAdmin['id'] = 999;
    $otherAdmin['username'] = 'other';
    $wrongOp = false;
    try {
        orange_restore_pca_finalize(
            $workRoot,
            $job5,
            $backupRoot,
            $otherAdmin,
            $seed5['pdo'],
            $pkgId,
            (string) ($ch5['required_confirmation_phrase'] ?? ''),
            (string) ($ch5['nonce'] ?? ''),
            'restore-test-password',
            'Owner explicitly authorizes irreversible production cutover window'
        );
    } catch (Throwable $e) {
        $wrongOp = in_array(trim($e->getMessage()), [
            'authorization_wrong_operator',
            'recent_authentication_failed',
        ], true);
    }
    pca_self_test($wrongOp, 'wrong operator rejected');

    // Wrong session.
    $ch5b = orange_restore_pca_create_challenge($workRoot, $job5, $backupRoot, $seed5['admin'], $seed5['pdo']);
    $chPath5 = orange_restore_pca_challenge_path($workRoot, $job5);
    $chBody5 = json_decode((string) file_get_contents($chPath5), true);
    $chBody5['session_id_hash'] = hash('sha256', 'other-session');
    file_put_contents($chPath5, json_encode($chBody5, JSON_PRETTY_PRINT) . "\n");
    $wrongSession = false;
    try {
        orange_restore_pca_finalize(
            $workRoot,
            $job5,
            $backupRoot,
            $seed5['admin'],
            $seed5['pdo'],
            $pkgId,
            (string) ($ch5b['required_confirmation_phrase'] ?? ''),
            (string) ($ch5b['nonce'] ?? ''),
            'restore-test-password',
            'Owner explicitly authorizes irreversible production cutover window'
        );
    } catch (Throwable $e) {
        $wrongSession = trim($e->getMessage()) === 'authorization_wrong_session';
    }
    pca_self_test($wrongSession, 'wrong session rejected');

    // Expired auth challenge.
    $ch5c = orange_restore_pca_create_challenge($workRoot, $job5, $backupRoot, $seed5['admin'], $seed5['pdo']);
    $chBody5c = json_decode((string) file_get_contents(orange_restore_pca_challenge_path($workRoot, $job5)), true);
    $chBody5c['expires_at'] = gmdate('c', time() - 5);
    file_put_contents(orange_restore_pca_challenge_path($workRoot, $job5), json_encode($chBody5c, JSON_PRETTY_PRINT) . "\n");
    $chExpired = false;
    try {
        orange_restore_pca_finalize(
            $workRoot,
            $job5,
            $backupRoot,
            $seed5['admin'],
            $seed5['pdo'],
            $pkgId,
            (string) ($ch5c['required_confirmation_phrase'] ?? ''),
            (string) ($ch5c['nonce'] ?? ''),
            'restore-test-password',
            'Owner explicitly authorizes irreversible production cutover window'
        );
    } catch (Throwable $e) {
        $chExpired = trim($e->getMessage()) === 'authorization_challenge_expired';
    }
    pca_self_test($chExpired, 'expired auth challenge rejected');

    // Consume once; second consume is idempotent when cutover_started.
    pi_authorize_production_cutover($workRoot, $backupRoot, $job5, $seed5['admin'], $seed5['pdo'], $pkgId);
    orange_restore_pca_consume_for_cutover_start($workRoot, $job5);
    $after = orange_restore_pca_load_record($workRoot, $job5);
    pca_self_test(
        !empty($after['authorization_consumed']) && !empty($after['cutover_started']),
        'consume: marks authorization_consumed + cutover_started'
    );
    orange_restore_pca_consume_for_cutover_start($workRoot, $job5);
    $resumeGate = orange_restore_pca_validate_for_import($workRoot, $job5, $backupRoot);
    pca_self_test(($resumeGate['ok'] ?? false) === true, 'consume: resume gate still ok after cutover_started');

    // Import validate_entry wiring.
    $engine = (string) file_get_contents(
        $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
        . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_import.php'
    );
    pca_self_test(
        str_contains($engine, 'orange_restore_pca_validate_for_import')
        && str_contains($engine, 'orange_restore_pca_consume_for_cutover_start'),
        'import engine: validates + consumes authorization (no other execution redesign)'
    );

    pi_retire_job($workRoot, $job5);

    echo "\nRESULT: {$passes} passed, {$failures} failed\n";
} catch (Throwable $e) {
    echo 'THROWABLE: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $failures++;
    echo "RESULT: {$passes} passed, {$failures} failed\n";
} finally {
    if ($tmpRoot !== '' && function_exists('pi_rmtree')) {
        pi_rmtree($tmpRoot);
    }
}

exit($failures > 0 ? 1 : 0);
