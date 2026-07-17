<?php

declare(strict_types=1);

/**
 * Phase 3B.3B3 — dedicated pre-restore backup gate self-test (isolated fixtures).
 *
 * Does NOT create a production Full backup unless ORANGE_PRE_RESTORE_BACKUP_INTEGRATION=1
 * and no engine override is set.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
// Do not require config.php — ordinary self-tests must run without .env.php / production DB.
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_retention.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_pre_restore_backup.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_dry_run.php';

$failures = 0;
$passes = 0;

function pre_backup_self_test(bool $ok, string $label): void
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

function pre_backup_test_write_zip(string $path, array $files): void
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($files as $name => $body) {
                $zip->addFromString((string) $name, (string) $body);
            }
            $zip->close();

            return;
        }
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

function pre_backup_test_seed_package(string $pkgDir, string $pkgId): void
{
    if (!is_dir($pkgDir)) {
        mkdir($pkgDir, 0775, true);
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    $gz = gzencode("SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\n", 1);
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, $gz !== false ? $gz : str_repeat('x', 32));
    pre_backup_test_write_zip($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel, ['a.txt' => 'hello']);
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
 * @return array{job_id:string}
 */
function pre_backup_test_make_approved_job(string $workRoot, string $backupRoot, string $sourceId): array
{
    $job = orange_restore_fw_create($workRoot, [
        'package_id' => $sourceId,
        'package_type' => 'full_disaster',
        'created_by' => 'superadmin',
        'created_by_admin_id' => 1,
    ]);
    $jobId = (string) $job['job_id'];
    $dry = orange_restore_dry_run_execute($workRoot, $jobId, [
        'backup_root' => $backupRoot,
        'operator_username' => 'superadmin',
    ]);
    $afterDry = orange_restore_fw_read($workRoot, $jobId);
    if ((string) ($afterDry['status'] ?? '') !== ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED) {
        $overall = (string) (($dry['report']['overall_result'] ?? $afterDry['dry_run_overall_result'] ?? ''));
        throw new RuntimeException('dry_run_not_completed:' . (string) ($afterDry['status'] ?? '') . ':' . $overall);
    }
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
            'execution_started' => false,
            'cli_invoked' => false,
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
    $j['execution_started'] = false;
    orange_restore_fw_write($workRoot, $j);
    orange_restore_prepare_execution_contract($workRoot, $jobId, $backupRoot);

    return ['job_id' => $jobId];
}

$configured = '';
try {
    $env = orange_backup_load_env_array($projectRoot);
    $configured = orange_backup_backup_root_candidate($env, $projectRoot);
} catch (Throwable) {
    $configured = '';
}
if ($configured === '' || !is_dir($configured)) {
    echo "SKIP: configured backup root unavailable\n";
    echo "PRE_RESTORE_BACKUP_TEST_RESULT: SKIP\n";
    exit(0);
}

$selfBase = rtrim($configured, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . '_self_tests';
if (!is_dir($selfBase)) {
    @mkdir($selfBase, 0775, true);
}
$tmpRoot = $selfBase . DIRECTORY_SEPARATOR . 'pre_restore_' . bin2hex(random_bytes(4));
$backupRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'backups';
$workRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'restore_work';
$fakeProject = $tmpRoot . DIRECTORY_SEPARATOR . 'fake_project';
foreach ([$tmpRoot, $backupRoot, $workRoot, $fakeProject, $backupRoot . DIRECTORY_SEPARATOR . 'snapshots', $backupRoot . DIRECTORY_SEPARATOR . 'locks'] as $d) {
    if (!is_dir($d)) {
        mkdir($d, 0775, true);
    }
}
file_put_contents($fakeProject . DIRECTORY_SEPARATOR . '.env.php', "<?php\nreturn ['ORANGE_BACKUP_ROOT' => " . var_export($backupRoot, true) . "];\n");

$cleanup = static function () use ($tmpRoot): void {
    $norm = str_replace('\\', '/', $tmpRoot);
    if ($tmpRoot === '' || !str_contains($norm, '/_self_tests/')) {
        return;
    }
    if (!is_dir($tmpRoot)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $path = $file->getPathname();
        $file->isDir() ? @rmdir($path) : @unlink($path);
    }
    @rmdir($tmpRoot);
};
register_shutdown_function($cleanup);

try {
    $sourceId = '2026-07-01_120000';
    pre_backup_test_seed_package($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $sourceId, $sourceId);

    $made = pre_backup_test_make_approved_job($workRoot, $backupRoot, $sourceId);
    $jobId = $made['job_id'];

    $unapproved = orange_restore_fw_create($workRoot, [
        'package_id' => $sourceId,
        'package_type' => 'full_disaster',
        'created_by' => 'superadmin',
        'created_by_admin_id' => 1,
    ]);
    $unapprovedRejected = false;
    try {
        orange_restore_pre_backup_request($workRoot, (string) $unapproved['job_id'], $backupRoot, ['username' => 'superadmin']);
    } catch (Throwable $e) {
        $unapprovedRejected = in_array(trim($e->getMessage()), [
            'invalid_status',
            'final_approval_missing',
            'contract_missing',
        ], true);
    }
    pre_backup_self_test($unapprovedRejected, 'unapproved job rejected');
    // Clear active waiting_confirmation fixture so later creates are allowed.
    orange_restore_fw_write($workRoot, array_merge(orange_restore_fw_read($workRoot, (string) $unapproved['job_id']), [
        'status' => ORANGE_RESTORE_FW_STATUS_CANCELLED,
        'phase' => ORANGE_RESTORE_FW_PHASE_CANCELLED,
    ]));
    orange_restore_fw_release_lock($workRoot, (string) $unapproved['job_id']);

    $countryJob = orange_restore_fw_create($workRoot, [
        'package_id' => '2026-07-01_130000',
        'package_type' => 'country_recovery',
        'country_code' => 'KW',
        'created_by' => 'superadmin',
        'created_by_admin_id' => 1,
    ]);
    $countryRejected = false;
    try {
        orange_restore_pre_backup_request($workRoot, (string) $countryJob['job_id'], $backupRoot, ['username' => 'superadmin']);
    } catch (Throwable $e) {
        $countryRejected = trim($e->getMessage()) === 'country_production_restore_not_enabled';
    }
    pre_backup_self_test($countryRejected, 'Country job rejected for production preparation');
    orange_restore_fw_write($workRoot, array_merge(orange_restore_fw_read($workRoot, (string) $countryJob['job_id']), [
        'status' => ORANGE_RESTORE_FW_STATUS_CANCELLED,
        'phase' => ORANGE_RESTORE_FW_PHASE_CANCELLED,
    ]));
    orange_restore_fw_release_lock($workRoot, (string) $countryJob['job_id']);

    $req = orange_restore_pre_backup_request($workRoot, $jobId, $backupRoot, ['username' => 'superadmin', 'id' => 1]);
    pre_backup_self_test(($req['cli_needed'] ?? false) === true, 'approved Full job can request preparation');
    pre_backup_self_test(($req['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING, 'request moves to pending');
    pre_backup_self_test(($req['execution_started'] ?? true) === false, 'request keeps execution_started false');

    $req2 = orange_restore_pre_backup_request($workRoot, $jobId, $backupRoot, ['username' => 'superadmin', 'id' => 1]);
    pre_backup_self_test(($req2['idempotent'] ?? false) === true, 'duplicate request is idempotent');

    $apiSrc = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'request-pre-restore-backup.php');
    pre_backup_self_test(
        !str_contains($apiSrc, 'orange_restore_pre_backup_run_cli')
        && !str_contains($apiSrc, 'orange_backup_run_full')
        && str_contains($apiSrc, 'restore_admin_api_require_csrf'),
        'HTTP request does not run backup'
    );

    $cliSrc = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_prepare_backup.php');
    pre_backup_self_test(str_contains($cliSrc, "PHP_SAPI !== 'cli'") && str_contains($cliSrc, '--job='), 'CLI-only gate with job id only');

    $anchorSeq = 0;
    $GLOBALS['orange_pre_restore_backup_engine_override'] = static function () use ($backupRoot, &$anchorSeq): array {
        $anchorSeq++;
        // Unique second-precision package ids (retention name pattern is fixed).
        sleep(1);
        $anchorId = gmdate('Y-m-d_His');
        pre_backup_test_seed_package($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $anchorId, $anchorId);

        return [
            'ok' => true,
            'snapshot' => $anchorId,
            'backend' => 'php_pdo',
            'message' => 'mock full backup #' . $anchorSeq,
            'exit_code' => 0,
        ];
    };
    $GLOBALS['orange_pre_restore_backup_verify_override'] = static function (): array {
        return [
            'ok' => true,
            'errors' => [],
            'manifest' => [
                'schema_revision' => ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION,
                'export_backend' => 'php_pdo',
                'backup_status' => 'success',
            ],
            'health' => ['package_status' => 'healthy'],
        ];
    };
    $GLOBALS['orange_pre_restore_backup_drv_override'] = static function (): array {
        return ['overall_result' => 'pass', 'recovery_score' => 95];
    };

    $run = orange_restore_pre_backup_run_cli($fakeProject, $workRoot, $backupRoot, $jobId, 'tester');
    pre_backup_self_test(($run['ok'] ?? false) === true, 'successful Full backup anchor');
    pre_backup_self_test(($run['retention_pinned'] ?? false) === true, 'retention pin persists');
    pre_backup_self_test(($run['execution_started'] ?? true) === false, 'execution_started remains false');
    pre_backup_self_test(
        (orange_restore_fw_read($workRoot, $jobId)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
        'stops at pre_restore_backup_ready'
    );

    $runDup = orange_restore_pre_backup_run_cli($fakeProject, $workRoot, $backupRoot, $jobId, 'tester');
    pre_backup_self_test(($runDup['idempotent'] ?? false) === true && ($runDup['ok'] ?? false) === true, 'duplicate CLI run does not create a second ready anchor');

    $oldId = '2020-01-01_000000';
    pre_backup_test_seed_package($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $oldId, $oldId);
    $retention = orange_backup_retention_apply_full_snapshots(
        $backupRoot,
        $backupRoot . DIRECTORY_SEPARATOR . 'snapshots',
        null,
        1,
        null
    );
    $pinKept = false;
    foreach ($retention['kept'] as $row) {
        if (($row['name'] ?? '') === (string) ($run['rollback_package_id'] ?? '')
            && ($row['reason'] ?? '') === 'skipped_due_to_pin'
            && !isset($row['path'])) {
            $pinKept = true;
        }
    }
    pre_backup_self_test($pinKept, 'pinned package is skipped by retention without path');

    // Retention may remove the unpinned source restore package — reseed for later jobs.
    pre_backup_test_seed_package($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $sourceId, $sourceId);

    // Force cancel ready so a new active job can prepare.
    $readyJob = orange_restore_fw_read($workRoot, $jobId);
    $readyJob['status'] = ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED;
    $readyJob['phase'] = ORANGE_RESTORE_FW_PHASE_EXECUTION_CANCELLED;
    orange_restore_fw_write($workRoot, $readyJob);
    orange_restore_exec_release_lock($workRoot, $jobId);
    orange_restore_pre_backup_release_lock($workRoot, $jobId);

    $made2 = pre_backup_test_make_approved_job($workRoot, $backupRoot, $sourceId);
    $job2Id = $made2['job_id'];
    orange_restore_pre_backup_request($workRoot, $job2Id, $backupRoot, ['username' => 'superadmin']);
    $GLOBALS['orange_pre_restore_backup_verify_override'] = static function (): array {
        return ['ok' => false, 'errors' => ['mock verify fail'], 'manifest' => null, 'health' => null];
    };
    $failVerify = orange_restore_pre_backup_run_cli($fakeProject, $workRoot, $backupRoot, $job2Id, 'tester');
    pre_backup_self_test(($failVerify['ok'] ?? true) === false && ($failVerify['code'] ?? '') === 'verify_failed', 'verification failure blocks readiness');
    pre_backup_self_test(
        (orange_restore_fw_read($workRoot, $job2Id)['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED,
        'failed attempt preserves forensic status'
    );
    pre_backup_self_test(is_file(orange_restore_pre_backup_record_path($workRoot, $job2Id)), 'failed attempt preserves metadata record');

    $GLOBALS['orange_pre_restore_backup_verify_override'] = static function (): array {
        return [
            'ok' => true,
            'errors' => [],
            'manifest' => [
                'schema_revision' => ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION,
                'export_backend' => 'php_pdo',
                'backup_status' => 'success',
            ],
            'health' => ['package_status' => 'healthy'],
        ];
    };
    $GLOBALS['orange_pre_restore_backup_drv_override'] = static function (): array {
        return ['overall_result' => 'pass', 'recovery_score' => 95];
    };
    $retryReq = orange_restore_pre_backup_request($workRoot, $job2Id, $backupRoot, ['username' => 'superadmin']);
    pre_backup_self_test(($retryReq['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING, 'retry policy from failed');
    $retryRun = orange_restore_pre_backup_run_cli($fakeProject, $workRoot, $backupRoot, $job2Id, 'tester');
    pre_backup_self_test(
        ($retryRun['ok'] ?? false) === true,
        'retry succeeds to ready' . (empty($retryRun['ok']) ? (' code=' . (string) ($retryRun['code'] ?? '')) : '')
    );

    // DRV failure job
    $j2ready = orange_restore_fw_read($workRoot, $job2Id);
    $j2ready['status'] = ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED;
    orange_restore_fw_write($workRoot, $j2ready);
    orange_restore_exec_release_lock($workRoot, $job2Id);

    $made3 = pre_backup_test_make_approved_job($workRoot, $backupRoot, $sourceId);
    $job3Id = $made3['job_id'];
    orange_restore_pre_backup_request($workRoot, $job3Id, $backupRoot, ['username' => 'superadmin']);
    $GLOBALS['orange_pre_restore_backup_drv_override'] = static function (): array {
        return ['overall_result' => 'fail', 'recovery_score' => 10];
    };
    $drvFail = orange_restore_pre_backup_run_cli($fakeProject, $workRoot, $backupRoot, $job3Id, 'tester');
    pre_backup_self_test(($drvFail['code'] ?? '') === 'drv_failed', 'DRV failure blocks readiness when required');

    orange_restore_pre_backup_release_lock($workRoot, $job3Id);
    orange_restore_pre_backup_acquire_lock($workRoot, 'other_job', 'other');
    $lockStatus = orange_restore_pre_backup_acquire_lock($workRoot, $job3Id, 'tester');
    pre_backup_self_test(($lockStatus['ok'] ?? true) === false, 'lock contention');
    orange_restore_pre_backup_release_lock($workRoot, 'other_job');

    file_put_contents(orange_restore_pre_backup_lock_path($workRoot), json_encode([
        'job_id' => 'stale_test',
        'owner' => 'x',
        'pid' => 99999999,
        'acquired_at' => gmdate('c'),
        'heartbeat_at' => gmdate('c'),
    ]) . "\n");
    $freshLock = orange_restore_pre_backup_lock_status($workRoot);
    pre_backup_self_test(($freshLock['stale'] ?? true) === false, 'stale lock conservative handling (fresh not stale)');
    orange_restore_pre_backup_release_lock($workRoot, 'stale_test');

    $ap = json_decode((string) file_get_contents(orange_restore_final_approval_record_path($workRoot, $job3Id)), true);
    $ap['approved_at'] = gmdate('c', time() + 99);
    file_put_contents(orange_restore_final_approval_record_path($workRoot, $job3Id), json_encode($ap) . "\n");
    $changed = orange_restore_pre_backup_revalidate($workRoot, $job3Id, $backupRoot);
    pre_backup_self_test(($changed['ok'] ?? true) === false, 'changed package/plan/approval rejected');

    pre_backup_self_test(!is_file($projectRoot . '/admin/api/restore/job/unpin.php'), 'no unpin endpoint');
    pre_backup_self_test(!is_file($projectRoot . '/admin/api/restore/job/execute.php'), 'no execute endpoint');
    pre_backup_self_test(!is_file($projectRoot . '/admin/api/restore/job/rollback.php'), 'no rollback endpoint');

    $mod = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php');
    pre_backup_self_test(
        !str_contains($mod, 'orange_restore_full_staging_run(')
        && !str_contains($mod, 'orange_restore_e2e_start_full(')
        && !str_contains($mod, 'orange_restore_orchestrator_database_cutover(')
        && !str_contains($mod, 'mysqli_query'),
        'no restore/SQL/cutover execution added'
    );

    $pub = orange_restore_pre_backup_public_record(orange_restore_pre_backup_load_record($workRoot, $jobId) ?? []);
    pre_backup_self_test(!isset($pub['package_path']) && !isset($pub['snapshot_path']), 'safe API redaction');

    echo 'PRE_RESTORE_BACKUP_TEST_RESULT: ' . ($failures === 0 ? 'PASS' : 'FAIL') . "\n";
    echo 'TOTAL_PASS: ' . $passes . "\n";
    echo 'TOTAL_FAIL: ' . $failures . "\n";
    exit($failures > 0 ? 1 : 0);
} catch (Throwable $e) {
    echo 'THROWABLE: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    echo "PRE_RESTORE_BACKUP_TEST_RESULT: FAIL\n";
    echo 'TOTAL_PASS: ' . $passes . "\n";
    echo 'TOTAL_FAIL: ' . ($failures + 1) . "\n";
    exit(1);
}
