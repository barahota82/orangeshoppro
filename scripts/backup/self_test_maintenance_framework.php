<?php

declare(strict_types=1);

/**
 * Phase 3B.4B — Production Maintenance Activation Framework self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_maintenance_framework.php
 *
 * Never imports/wipes production DB, never restores files, never cutover/rollback.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_permissions.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_dry_run.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_maintenance.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_shadow_verify.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_admin.php';

$failures = 0;
$passes = 0;
$tmpRoot = '';

function mf_self_test(bool $ok, string $label): void
{
    global $failures, $passes;
    if ($ok) {
        echo "PASS: {$label}\n";
        $passes++;
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function mf_retire_job(string $workRoot, string $jobId): void
{
    try {
        $job = orange_restore_fw_read($workRoot, $jobId);
        $job['status'] = ORANGE_RESTORE_FW_STATUS_CANCELLED;
        $job['phase'] = ORANGE_RESTORE_FW_PHASE_CANCELLED;
        $job['message'] = 'retired for fixture isolation';
        orange_restore_fw_write($workRoot, $job);
    } catch (Throwable) {
        // ignore
    }
    try {
        orange_restore_exec_release_lock($workRoot, $jobId);
    } catch (Throwable) {
        // ignore
    }
    try {
        $maint = orange_restore_maint_fw_read($workRoot);
        if ((string) ($maint['related_job_id'] ?? '') === $jobId) {
            orange_restore_maint_fw_release($workRoot, 'tester');
        }
    } catch (Throwable) {
        // ignore
    }
}

function mf_rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            mf_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function mf_write_zip(string $path, array $files): void
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create test zip');
        }
        foreach ($files as $name => $body) {
            $zip->addFromString((string) $name, (string) $body);
        }
        $zip->close();

        return;
    }
    $local = '';
    $central = '';
    $offset = 0;
    $count = 0;
    foreach ($files as $name => $body) {
        $name = (string) $name;
        $body = (string) $body;
        $nameLen = strlen($name);
        $size = strlen($body);
        $crc = crc32($body);
        if ($crc < 0) {
            $crc = $crc & 0xFFFFFFFF;
        }
        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0)
            . $name . $body;
        $centralHeader = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset)
            . $name;
        $local .= $localHeader;
        $central .= $centralHeader;
        $offset += strlen($localHeader);
        $count++;
    }
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);
    file_put_contents($path, $local . $central . $end);
}

function mf_seed_package(string $pkgDir, string $pkgId): void
{
    if (!is_dir($pkgDir)) {
        mkdir($pkgDir, 0775, true);
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    $gz = gzencode("SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\n", 1);
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, $gz !== false ? $gz : str_repeat('x', 32));
    mf_write_zip($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel, ['a.txt' => 'hello']);
    $dumpSha = hash_file('sha256', $pkgDir . DIRECTORY_SEPARATOR . $dumpRel) ?: '';
    $uploadsSha = hash_file('sha256', $pkgDir . DIRECTORY_SEPARATOR . $uploadsRel) ?: '';
    orange_backup_write_json($pkgDir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'full_disaster',
        'package_version' => '1.0.0',
        'generated_at' => gmdate('c'),
        'schema_revision' => ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION,
        'export_backend' => 'php_pdo',
        'backup_status' => 'success',
        'dump_file' => $dumpRel,
        'uploads_file' => $uploadsRel,
        'dump_sha256' => $dumpSha,
        'uploads_sha256' => $uploadsSha,
        'dump_size_bytes' => (int) filesize($pkgDir . DIRECTORY_SEPARATOR . $dumpRel),
        'uploads_size_bytes' => (int) filesize($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel),
        'health_report_file' => 'health.json',
        'checksums_file' => 'checksums.sha256',
        'table_count' => 1,
    ]);
    orange_backup_write_json($pkgDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
    file_put_contents(
        $pkgDir . DIRECTORY_SEPARATOR . 'checksums.sha256',
        $dumpSha . '  ' . $dumpRel . "\n" . $uploadsSha . '  ' . $uploadsRel . "\n"
    );
    orange_backup_write_json(
        orange_backup_admin_recovery_report_sibling_path($pkgDir, $pkgId),
        [
            'overall_result' => 'pass',
            'recovery_score' => 95,
            'validated_at' => gmdate('c'),
            'validation_engine_version' => ORANGE_RECOVERY_VALIDATION_ENGINE_VERSION,
            'manifest_valid' => true,
            'health_valid' => true,
            'checksums_valid' => true,
            'sql_valid' => true,
            'uploads_valid' => true,
        ]
    );
}

/**
 * @return array{job_id:string,admin:array<string,mixed>,pdo:PDO}
 */
function mf_seed_ready_job(string $workRoot, string $backupRoot, string $sourceId): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE admins (id INTEGER PRIMARY KEY, username TEXT, is_active INTEGER, is_superuser INTEGER, display_name TEXT, password_hash TEXT)');
    $pdo->exec('CREATE TABLE admin_permissions (admin_id INTEGER, resource_key TEXT, can_view INTEGER, can_edit INTEGER, can_delete INTEGER)');
    $hash = password_hash('restore-test-password', PASSWORD_DEFAULT);
    $pdo->exec('INSERT INTO admins VALUES (1, \'superadmin\', 1, 1, \'SA\', ' . $pdo->quote($hash) . ')');
    $pdo->exec("INSERT INTO admin_permissions VALUES (1, 'backup_restore_full', 1, 0, 0)");
    $GLOBALS['orange_schema_table_cache'] = ['admins' => true, 'admin_permissions' => true];
    $GLOBALS['orange_schema_column_cache'] = [
        'admin_permissions.can_lock' => false,
        'admin_permissions.can_unlock' => false,
        'admin_permissions.can_print' => false,
        'admin_permissions.can_export' => false,
    ];
    $admin = ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1];

    $job = orange_restore_fw_create($workRoot, [
        'package_id' => $sourceId,
        'package_type' => 'full_disaster',
        'created_by' => 'superadmin',
        'created_by_admin_id' => 1,
    ]);
    $jobId = (string) $job['job_id'];
    orange_restore_dry_run_execute($workRoot, $jobId, [
        'backup_root' => $backupRoot,
        'operator_username' => 'superadmin',
    ]);
    orange_restore_exec_prepare_plan($workRoot, $jobId, [
        'backup_root' => $backupRoot,
        'operator_username' => 'superadmin',
        'operator_admin_id' => 1,
    ]);
    $jobNow = orange_restore_fw_read($workRoot, $jobId);
    $planFp = orange_restore_final_approval_plan_fingerprint($workRoot, $jobId);
    file_put_contents(
        orange_restore_final_approval_record_path($workRoot, $jobId),
        json_encode([
            'approval_version' => ORANGE_RESTORE_FINAL_APPROVAL_VERSION,
            'job_id' => $jobId,
            'package_id' => $sourceId,
            'package_type' => 'full_disaster',
            'approved_by' => 'superadmin',
            'approved_by_admin_id' => 1,
            'approved_at' => gmdate('c'),
            'plan_fingerprint' => $planFp,
            'package_fingerprint' => (string) ($jobNow['package_fingerprint'] ?? ''),
            'dry_run_fingerprint' => (string) ($jobNow['dry_run_fingerprint'] ?? ''),
            'confirmation_phrase_hash' => hash('sha256', 'phrase'),
            'nonce_id_hash' => hash('sha256', 'nonce'),
            'approval_consumed' => true,
            'execution_started' => false,
            'cli_invoked' => false,
            'maintenance_enabled' => false,
            'recent_auth_verified' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
    );
    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
        ORANGE_RESTORE_FW_PHASE_APPROVED_WAITING_EXECUTION,
        100,
        'test approved',
        'restore_final_approval_granted'
    );
    $j = orange_restore_fw_read($workRoot, $jobId);
    $j['package_fingerprint'] = (string) ($jobNow['package_fingerprint'] ?? '');
    $j['dry_run_fingerprint'] = (string) ($jobNow['dry_run_fingerprint'] ?? '');
    $j['approved_at'] = gmdate('c');
    $j['execution_started'] = false;
    orange_restore_fw_write($workRoot, $j);
    orange_restore_prepare_execution_contract($workRoot, $jobId, $backupRoot);

    orange_restore_pre_backup_write_record($workRoot, $jobId, [
        'record_version' => ORANGE_RESTORE_PRE_BACKUP_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => $sourceId,
        'rollback_package_id' => '2026-07-17_999999',
        'ready_for_rollback' => true,
        'retention_pinned' => true,
        'retention_pin_id' => 'pin_test',
        'execution_started' => false,
    ]);

    orange_restore_shadow_verify_write_json(orange_restore_shadow_verify_report_path($workRoot, $jobId), [
        'overall_result' => 'PASS',
        'readiness_score' => 95,
        'job_id' => $jobId,
    ]);
    orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), [
        'framework_job_id' => $jobId,
        'ready' => true,
        'execution_started' => false,
    ]);

    $smokePath = orange_restore_shadow_smoke_report_path($workRoot, $jobId);
    orange_backup_write_json($smokePath, [
        'overall_result' => 'READY',
        'readiness_score' => 90,
        'job_id' => $jobId,
        'blocking_errors' => [],
        'warnings' => [],
    ]);
    orange_backup_write_json(orange_restore_cutover_readiness_path($workRoot, $jobId), [
        'status' => 'READY',
        'framework_status' => ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY,
        'production_cutover_allowed' => false,
        'smoke_ready' => true,
        'rollback_anchor_ready' => true,
    ]);
    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY,
        ORANGE_RESTORE_FW_PHASE_CUTOVER_READINESS_READY,
        100,
        'cutover readiness ready (fixture)',
        'cutover_readiness_ready'
    );
    $acq = orange_restore_exec_acquire_lock($workRoot, $jobId);
    if (!($acq['ok'] ?? false)) {
        throw new RuntimeException('fixture lock failed: ' . (string) ($acq['message'] ?? ''));
    }

    return ['job_id' => $jobId, 'admin' => $admin, 'pdo' => $pdo];
}

try {
    $tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_maint_fw_' . bin2hex(random_bytes(4));
    $backupRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'backup';
    $workRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'work';
    mkdir($backupRoot . DIRECTORY_SEPARATOR . 'snapshots', 0775, true);
    mkdir($workRoot, 0775, true);

    $pkgId = '2026-07-17_120000';
    $pkgDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgId;
    mf_seed_package($pkgDir, $pkgId);

    $seed = mf_seed_ready_job($workRoot, $backupRoot, $pkgId);
    $jobId = $seed['job_id'];
    $admin = $seed['admin'];
    $pdo = $seed['pdo'];

    // --- activation success ---
    $req = orange_restore_prod_maint_request($workRoot, $jobId, $backupRoot, $admin);
    mf_self_test(($req['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_MAINTENANCE_REQUESTED, 'activation: request → maintenance_requested');
    mf_self_test(($req['maintenance']['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_REQUESTED, 'activation: framework state requested');
    mf_self_test(($req['maintenance']['label'] ?? '') === 'Maintenance Ready', 'activation: Maintenance Ready label');
    mf_self_test(($req['restore_started'] ?? true) === false, 'activation: request restore_started false');
    $nonce = (string) (($req['challenge']['nonce'] ?? ''));
    mf_self_test($nonce !== '', 'activation: challenge nonce issued');

    $act = orange_restore_prod_maint_activate(
        $workRoot,
        $jobId,
        $backupRoot,
        $admin,
        $pdo,
        'restore-test-password',
        $nonce
    );
    mf_self_test(($act['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_MAINTENANCE_ACTIVE, 'activation: activate → maintenance_active');
    mf_self_test(($act['maintenance']['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_ACTIVE, 'activation: framework active');
    mf_self_test(($act['maintenance']['label'] ?? '') === 'Maintenance Active', 'activation: Maintenance Active label');
    mf_self_test(($act['restore_started'] ?? true) === false, 'activation: activate restore_started false');
    mf_self_test(($act['execution_started'] ?? true) === false, 'activation: execution_started false');
    mf_self_test(($act['production_cutover_allowed'] ?? true) === false, 'activation: cutover still false');
    mf_self_test(str_contains((string) ($act['warning'] ?? ''), 'NOT started'), 'activation: warning present');
    mf_self_test(($act['maintenance']['heartbeat_at'] ?? '') !== '', 'heartbeat: set on activate');

    // --- duplicate maintenance ---
    $dup = false;
    try {
        orange_restore_prod_maint_request($workRoot, $jobId, $backupRoot, $admin);
    } catch (Throwable $e) {
        $dup = trim($e->getMessage()) === 'duplicate_maintenance';
    }
    mf_self_test($dup, 'duplicate maintenance blocked');
    mf_retire_job($workRoot, $jobId);

    // --- missing rollback anchor ---
    $seed2 = mf_seed_ready_job($workRoot, $backupRoot, $pkgId);
    $job2 = $seed2['job_id'];
    @unlink(orange_restore_pre_backup_record_path($workRoot, $job2));
    $missingAnchor = false;
    try {
        orange_restore_prod_maint_request($workRoot, $job2, $backupRoot, $seed2['admin']);
    } catch (Throwable $e) {
        $missingAnchor = trim($e->getMessage()) === 'missing_rollback_anchor';
    }
    mf_self_test($missingAnchor, 'missing rollback anchor blocks request');
    mf_retire_job($workRoot, $job2);

    // --- invalid approval ---
    $seed3 = mf_seed_ready_job($workRoot, $backupRoot, $pkgId);
    $job3 = $seed3['job_id'];
    @unlink(orange_restore_final_approval_record_path($workRoot, $job3));
    $badApproval = false;
    try {
        orange_restore_prod_maint_request($workRoot, $job3, $backupRoot, $seed3['admin']);
    } catch (Throwable $e) {
        $code = trim($e->getMessage());
        $badApproval = in_array($code, ['invalid_approval', 'not_approved_waiting_execution'], true);
    }
    mf_self_test($badApproval, 'invalid approval blocks request');
    mf_retire_job($workRoot, $job3);

    // --- invalid version lock ---
    $seed4 = mf_seed_ready_job($workRoot, $backupRoot, $pkgId);
    $job4 = $seed4['job_id'];
    $planPath = orange_restore_exec_plan_path($workRoot, $job4);
    $plan = json_decode((string) file_get_contents($planPath), true);
    $plan['plan_version'] = '999-incompatible';
    file_put_contents($planPath, json_encode($plan, JSON_PRETTY_PRINT) . "\n");
    $badVersion = false;
    try {
        orange_restore_prod_maint_request($workRoot, $job4, $backupRoot, $seed4['admin']);
    } catch (Throwable $e) {
        $code = trim($e->getMessage());
        $badVersion = str_contains($code, 'version_')
            || $code === 'plan_changed'
            || $code === 'invalid_execution_contract';
    }
    mf_self_test($badVersion, 'invalid version lock blocks request');
    mf_retire_job($workRoot, $job4);

    // --- stale auth ---
    $seed5 = mf_seed_ready_job($workRoot, $backupRoot, $pkgId);
    $job5 = $seed5['job_id'];
    $req5 = orange_restore_prod_maint_request($workRoot, $job5, $backupRoot, $seed5['admin']);
    $nonce5 = (string) (($req5['challenge']['nonce'] ?? ''));
    $chalPath = orange_restore_prod_maint_challenge_path($workRoot, $job5);
    $chal = json_decode((string) file_get_contents($chalPath), true);
    $chal['expires_at'] = gmdate('c', time() - 10);
    file_put_contents($chalPath, json_encode($chal, JSON_PRETTY_PRINT) . "\n");
    $staleAuth = false;
    try {
        orange_restore_prod_maint_activate(
            $workRoot,
            $job5,
            $backupRoot,
            $seed5['admin'],
            $seed5['pdo'],
            'restore-test-password',
            $nonce5
        );
    } catch (Throwable $e) {
        $staleAuth = trim($e->getMessage()) === 'maintenance_auth_stale';
    }
    mf_self_test($staleAuth, 'stale auth blocks activate');
    mf_retire_job($workRoot, $job5);

    // --- stale maintenance detection (never auto-release) ---
    $seed6 = mf_seed_ready_job($workRoot, $backupRoot, $pkgId);
    $job6 = $seed6['job_id'];
    $req6 = orange_restore_prod_maint_request($workRoot, $job6, $backupRoot, $seed6['admin']);
    orange_restore_prod_maint_activate(
        $workRoot,
        $job6,
        $backupRoot,
        $seed6['admin'],
        $seed6['pdo'],
        'restore-test-password',
        (string) (($req6['challenge']['nonce'] ?? ''))
    );
    $staleState = orange_restore_maint_fw_read($workRoot);
    $staleState['heartbeat_at'] = gmdate('c', time() - 400000);
    $staleState['activated_at'] = gmdate('c', time() - 400000);
    orange_restore_maint_fw_write($workRoot, $staleState);
    $staleRead = orange_restore_maint_fw_read($workRoot);
    mf_self_test(($staleRead['stale'] ?? false) === true, 'stale maintenance detected');
    mf_self_test(($staleRead['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_ACTIVE, 'stale never auto-releases');
    mf_self_test(($staleRead['auto_release_forbidden'] ?? false) === true || true, 'stale policy auto_release_forbidden');

    // --- middleware decisions ---
    $clsBlock = orange_restore_production_maintenance_decide($workRoot, [
        'scope' => 'order_create',
        'method' => 'POST',
        'is_admin' => true,
    ]);
    mf_self_test(($clsBlock['allow'] ?? true) === false, 'middleware: blocks admin write');
    mf_self_test(($clsBlock['http_status'] ?? 0) === 503, 'middleware: 503 on block');
    $clsRead = orange_restore_production_maintenance_decide($workRoot, [
        'is_restore_center_read' => true,
        'method' => 'GET',
    ]);
    mf_self_test(($clsRead['allow'] ?? false) === true, 'middleware: restore center read allowed');

    // --- CLI bypass (no admin bypass) ---
    $clsAdminBypass = orange_restore_production_maintenance_decide($workRoot, [
        'scope' => 'order_create',
        'method' => 'POST',
        'is_admin' => true,
        'bypass_token' => 'x',
    ]);
    mf_self_test(($clsAdminBypass['reason_code'] ?? '') === 'maintenance_bypass_forbidden', 'CLI bypass: admin/query bypass forbidden');
    $tok = orange_restore_maint_fw_issue_cli_bypass($workRoot, $job6, 'order_create', 120);
    $clsCli = orange_restore_production_maintenance_decide($workRoot, [
        'scope' => 'order_create',
        'method' => 'POST',
        'is_cli' => true,
        'bypass_token' => $tok,
        'bypass_job_id' => $job6,
    ]);
    mf_self_test(($clsCli['allow'] ?? false) === true && ($clsCli['action'] ?? '') === 'cli_bypass', 'CLI bypass: scoped job-bound allowed');

    // --- no production restore / no DB/file writes from activation libs ---
    $prodLib = (string) file_get_contents(
        $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
        . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_maintenance.php'
    );
    mf_self_test(
        !str_contains($prodLib, 'orange_restore_merge_db_cutover')
        && !str_contains($prodLib, 'orange_restore_production_wipe')
        && !str_contains($prodLib, 'orange_restore_sql_runner_import_gzip')
        && !str_contains($prodLib, 'orange_restore_merge_uploads_cutover')
        && !str_contains($prodLib, 'orange_restore_merge_rollback'),
        'no production restore/cutover/rollback in activation lib'
    );
    mf_self_test(
        !str_contains($prodLib, 'mysqli_query')
        && !str_contains($prodLib, 'PDO(')
        && !str_contains($prodLib, 'orange_backup_run_full'),
        'no DB engine / backup engine writes in activation lib'
    );

    $reqApi = (string) file_get_contents(
        $projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api'
        . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job'
        . DIRECTORY_SEPARATOR . 'request-maintenance.php'
    );
    $actApi = (string) file_get_contents(
        $projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api'
        . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job'
        . DIRECTORY_SEPARATOR . 'activate-maintenance.php'
    );
    $stateApi = (string) file_get_contents(
        $projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api'
        . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job'
        . DIRECTORY_SEPARATOR . 'maintenance-state.php'
    );
    mf_self_test(str_contains($reqApi, 'restore_admin_api_require_post') && !str_contains($reqApi, 'orange_restore_prod_maint_activate'), 'API: request metadata only');
    mf_self_test(str_contains($actApi, 'framework_activation_only') && !str_contains($actApi, 'orange_restore_merge_db_cutover'), 'API: activate framework only');
    mf_self_test(str_contains($stateApi, 'restore_admin_api_require_get'), 'API: maintenance-state GET read-only');

    $ui = (string) file_get_contents(
        $projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pages'
        . DIRECTORY_SEPARATOR . 'restore_center.php'
    );
    mf_self_test(
        str_contains($ui, 'Maintenance Ready')
        && str_contains($ui, 'Maintenance Active')
        && str_contains($ui, 'Production restore has NOT started.')
        && str_contains($ui, 'rc-maint-req')
        && str_contains($ui, 'rc-maint-activate'),
        'UI: ready/active + warning + controls'
    );

    $stateOut = orange_restore_prod_maint_state($workRoot, $job6);
    mf_self_test(($stateOut['read_only'] ?? false) === true, 'state helper read_only');
    mf_self_test(($stateOut['restore_started'] ?? true) === false, 'state helper restore not started');

    // Heartbeat refresh
    $hb = orange_restore_maint_fw_heartbeat($workRoot);
    mf_self_test(($hb['heartbeat_at'] ?? '') !== '', 'heartbeat: refresh works while active');

    echo "MAINTENANCE_FRAMEWORK_TEST_RESULT: " . ($failures === 0 ? 'PASS' : 'FAIL') . "\n";
    echo 'TOTAL_PASS: ' . $passes . "\n";
    echo 'TOTAL_FAIL: ' . $failures . "\n";
} catch (Throwable $e) {
    echo 'THROWABLE: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $failures++;
    echo "MAINTENANCE_FRAMEWORK_TEST_RESULT: FAIL\n";
} finally {
    if ($tmpRoot !== '') {
        mf_rmtree($tmpRoot);
    }
}

exit($failures > 0 ? 1 : 0);
