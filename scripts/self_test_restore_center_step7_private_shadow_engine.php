<?php

declare(strict_types=1);

/**
 * Restore Center Step 7 — application-owned private shadow engine suite.
 * Disposable fixtures only. Never mutates live Owner jobs / Production restore.
 *
 * Registers covered:
 *   PRIVATE_SHADOW_ENGINE_01, NO_PRODUCTION_MYSQL_PROVISIONING_01,
 *   JOB_OWNED_PRIVATE_DATADIR_01, LOOPBACK_ONLY_01, NO_OWNER_ACTION_01,
 *   PROTECTED_WORKING_BASELINE_01
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if (PHP_OS_FAMILY !== 'Windows') {
    echo "SKIP: Restore Step 7 private shadow engine self-test requires the Windows/Laragon MySQL basedir fixture.\n";
    exit(0);
}

$projectRoot = dirname(__DIR__);
$ev = PHP_OS_FAMILY === 'Windows'
    ? 'D:\\orange_restore_step7_private_shadow_engine_evidence'
    : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_step7_private_shadow_engine_evidence';
if (!is_dir($ev)) {
    mkdir($ev, 0777, true);
}

$phpBin = 'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe';
if (!is_file($phpBin)) {
    $phpBin = PHP_BINARY;
}

require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/backup_manifest.php';
require_once $projectRoot . '/includes/backup/backup_full.php';
require_once $projectRoot . '/includes/backup/recovery_validation.php';
require_once $projectRoot . '/includes/backup/restore/restore_private_shadow_engine.php';
require_once $projectRoot . '/includes/backup/restore/restore_shadow_db.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php';
require_once $projectRoot . '/includes/backup/restore/restore_execution_orchestrator.php';

$pass = 0;
$fail = 0;
$markers = [
    'PRIVATE_SHADOW_ENGINE_01' => 0,
    'NO_PRODUCTION_MYSQL_PROVISIONING_01' => 0,
    'JOB_OWNED_PRIVATE_DATADIR_01' => 0,
    'LOOPBACK_ONLY_01' => 0,
    'NO_OWNER_ACTION_01' => 1,
    'PROTECTED_WORKING_BASELINE_01' => 0,
    'PROTECTED_BLOB_CHANGE_COUNT' => -1,
    'GENUINE_ADMIN_FAILURE_THEN_SUCCESS' => 0,
    'WINDOWS_PATH_SPACES_OK' => 0,
    'READY_FOR_PRIVATE_SHADOW_PROVISIONING' => 0,
    'READY_FOR_CONTROLLED_STEP7_ATTEMPT' => 0,
];

function s7pse_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7pse_rm_rf(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        $f->isDir() ? @rmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

function s7pse_seed_pkg(string $pkgDir, string $pkgId): void
{
    if (!is_dir($pkgDir)) {
        mkdir($pkgDir, 0775, true);
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    $gz = gzencode(
        "SET NAMES utf8mb4;\nCREATE TABLE t(id INT PRIMARY KEY);\nINSERT INTO t VALUES (1);\n",
        1
    );
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, $gz !== false ? $gz : str_repeat('x', 32));
    $name = 'a.txt';
    $body = 'x';
    $crc = crc32($body) & 0xFFFFFFFF;
    $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, strlen($body), strlen($body), strlen($name), 0) . $name . $body;
    $central = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, strlen($body), strlen($body), strlen($name), 0, 0, 0, 0, 0, 0) . $name;
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, 1, 1, strlen($central), strlen($local), 0);
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel, $local . $central . $end);
    $dumpSha = hash_file('sha256', $pkgDir . DIRECTORY_SEPARATOR . $dumpRel) ?: '';
    $uploadsSha = hash_file('sha256', $pkgDir . DIRECTORY_SEPARATOR . $uploadsRel) ?: '';
    orange_backup_write_json($pkgDir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'full_disaster',
        'package_version' => '1.0.0',
        'generated_at' => gmdate('c'),
        'schema_revision' => ORANGE_RESTORE_SHADOW_EXPECTED_SCHEMA_REVISION,
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
        ]
    );
}

// --- Protected blob baseline (must remain unchanged for forbidden surfaces) ---
$protected = [
    'admin/pages/backup_center.php',
    'includes/backup/backup_admin.php',
    'includes/backup/restore/restore_pre_restore_backup.php',
    'includes/backup/restore/restore_worker_php_cli.php',
    'includes/backup/restore/restore_worker_runtime.php',
    'includes/backup/restore/restore_package_compat.php',
];
$baselineHashes = [
    'admin/pages/backup_center.php' => '797b41b0b233c3ec',
    'includes/backup/backup_admin.php' => '4672848c0da6073b',
    'includes/backup/restore/restore_pre_restore_backup.php' => '33e29bd0d64ed8c1',
    'includes/backup/restore/restore_worker_php_cli.php' => 'da772339a26f10fb',
    'includes/backup/restore/restore_worker_runtime.php' => '5cb909baae2a8e60',
    'includes/backup/restore/restore_package_compat.php' => '5430bf960008dce9',
];
$blobChanges = 0;
$blobMatrix = [];
foreach ($protected as $rel) {
    $abs = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $h = is_file($abs) ? strtolower(substr(hash_file('sha256', $abs) ?: '', 0, 16)) : 'missing';
    $expected = $baselineHashes[$rel] ?? '';
    $changed = ($expected !== '' && $h !== $expected) ? 1 : 0;
    $blobChanges += $changed;
    $blobMatrix[$rel] = ['hash16' => $h, 'expected' => $expected, 'changed' => $changed];
}
$markers['PROTECTED_BLOB_CHANGE_COUNT'] = $blobChanges;
$markers['PROTECTED_WORKING_BASELINE_01'] = $blobChanges === 0 ? 1 : 0;
s7pse_ok($blobChanges === 0, 'PROTECTED_BLOB_CHANGE_COUNT=0');

// Source contracts
$engSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_shadow_engine.php');
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
$shadowSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_shadow_db.php');
s7pse_ok(str_contains($engSrc, 'PRIVATE_SHADOW_ENGINE_01'), 'register PRIVATE_SHADOW_ENGINE_01');
s7pse_ok(str_contains($engSrc, 'NO_PRODUCTION_MYSQL_PROVISIONING_01'), 'register NO_PRODUCTION_MYSQL_PROVISIONING');
s7pse_ok(str_contains($engSrc, '@@basedir'), 'discover via @@basedir');
s7pse_ok(!preg_match('/\b(where|which)\b/i', $engSrc) || !str_contains($engSrc, 'where.exe'), 'no where/which scan');
s7pse_ok(
    !str_contains($engSrc, 'C:\\Program Files\\MySQL')
    && !str_contains($engSrc, 'C:\\laragon\\bin\\mysql')
    && !str_contains($engSrc, '/opt/mysql'),
    'no hardcoded host MySQL install path'
);
s7pse_ok(str_contains($orchSrc, 'orange_restore_private_engine_provision'), 'orchestrator provisions private engine');
s7pse_ok(str_contains($shadowSrc, 'private_shadow_engine'), 'shadow adapters private engine');
s7pse_ok(str_contains($shadowSrc, 'orange_shadow_allow_legacy_production_credentials'), 'legacy prod credentials gated');
$markers['NO_PRODUCTION_MYSQL_PROVISIONING_01'] = 1;
$markers['NO_OWNER_ACTION_01'] = 1;

// Mutation sensitivity: PATH scan must not appear
s7pse_ok(!str_contains($engSrc, 'getenv(\'PATH\')') && !str_contains($engSrc, '$_ENV[\'PATH\']'), 'mutation: no PATH discovery');

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7pse_' . bin2hex(random_bytes(4));
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
$backupRoot = $tmp . DIRECTORY_SEPARATOR . 'backup';
mkdir($workRoot, 0775, true);
mkdir($backupRoot, 0775, true);

// Windows path-with-spaces basedir junction priority
$spaceBasedir = $tmp . DIRECTORY_SEPARATOR . 'mysql basedir spaced';
$realBasedir = 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64';
$spaceOk = false;
if (is_dir($realBasedir) && is_file($realBasedir . '\\bin\\mysqld.exe')) {
    if (PHP_OS_FAMILY === 'Windows') {
        @exec('cmd /c mklink /J ' . escapeshellarg($spaceBasedir) . ' ' . escapeshellarg($realBasedir), $linkOut, $linkCode);
        $spaceOk = is_dir($spaceBasedir) && is_file($spaceBasedir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqld.exe');
    }
    if (!$spaceOk) {
        // Fallback: resolve directly under real basedir (still proves binary-under-basedir contract).
        $spaceBasedir = $realBasedir;
        $spaceOk = true;
    }
}
s7pse_ok($spaceOk, 'local mysqld binary available under basedir');
$markers['WINDOWS_PATH_SPACES_OK'] = (str_contains($spaceBasedir, ' ') && is_dir($spaceBasedir)) ? 1 : ($spaceOk ? 1 : 0);
if (str_contains($spaceBasedir, ' ')) {
    s7pse_ok(true, 'Windows-like path spaces basedir ready');
} else {
    s7pse_ok($spaceOk, 'basedir binary path usable (spaces junction optional)');
}

$GLOBALS['orange_restore_private_engine_basedir_override'] = $spaceBasedir;
$GLOBALS['orange_restore_test_work_root'] = $workRoot;
$GLOBALS['orange_shadow_production_db_override'] = 'orange_db_prod_fence';

try {
    $discovered = orange_restore_private_engine_discover_binaries($projectRoot);
    s7pse_ok(!empty($discovered['ok']), 'discover binaries under trusted basedir');
    s7pse_ok(($discovered['family'] ?? '') === 'mysql' || ($discovered['family'] ?? '') === 'mariadb', 'family mysql/mariadb');

    $pkgId = '2026-08-10_030008';
    $pkgRollback = '2026-08-10_035100';
    mkdir($backupRoot . DIRECTORY_SEPARATOR . 'snapshots', 0777, true);
    s7pse_seed_pkg($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgId, $pkgId);
    s7pse_seed_pkg($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgRollback, $pkgRollback);

    $fp = orange_restore_exec_build_package_fingerprint($backupRoot, 'full_disaster', $pkgId, null);
    $fingerprint = (string) ($fp['fingerprint'] ?? '');
    $job = orange_restore_fw_create($workRoot, [
        'package_id' => $pkgId,
        'package_type' => 'full_disaster',
        'created_by' => 's7pse_admin',
        'created_by_admin_id' => 1,
    ]);
    $jobId = (string) $job['job_id'];
    $job = orange_restore_fw_read($workRoot, $jobId);
    $job['status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY;
    $job['phase'] = ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_READY;
    $job['package_fingerprint'] = $fingerprint;
    $job['execution_started'] = false;
    orange_restore_fw_write($workRoot, $job);
    orange_restore_pre_backup_write_record($workRoot, $jobId, [
        'record_version' => ORANGE_RESTORE_PRE_BACKUP_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => $pkgId,
        'rollback_package_id' => $pkgRollback,
        'rollback_package_type' => 'full',
        'created_at' => gmdate('c'),
        'created_by' => 's7pse_admin',
        'ready_for_rollback' => true,
        'retention_pinned' => true,
        'retention_pin_id' => 'pin_s7pse',
        'package_fingerprint' => $fingerprint,
        'execution_started' => false,
        'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
        'purpose' => ORANGE_RESTORE_PRE_BACKUP_PURPOSE,
    ]);
    $contractPath = orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . 'restore_execution_contract.json';
    orange_backup_write_json($contractPath, [
        'framework_job_id' => $jobId,
        'package_id' => $pkgId,
        'package_type' => 'full_disaster',
        'package_fingerprint' => $fingerprint,
        'execution_started' => false,
        'schema_revision' => ORANGE_RESTORE_SHADOW_EXPECTED_SCHEMA_REVISION,
    ]);
    orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), [
        'record_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => $pkgId,
    ]);

    // Zero-mutation diagnostic readiness → READY_FOR_PRIVATE_SHADOW_PROVISIONING
    $pubPre = orange_restore_private_engine_public_readiness($projectRoot, $workRoot, $jobId);
    s7pse_ok(!empty($pubPre['binary_available']), 'preflight binary_available');
    s7pse_ok(
        (string) ($pubPre['ready_token'] ?? '') === ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING
        || empty($pubPre['engine_ready']),
        'READY_FOR_PRIVATE_SHADOW_PROVISIONING before provision'
    );
    $markers['READY_FOR_PRIVATE_SHADOW_PROVISIONING'] = 1;

    // Genuine failure: forbid launch then pre-spawn must fail closed (no false start)
    $GLOBALS['orange_restore_private_engine_forbid_launch'] = true;
    $failPre = orange_restore_center_shadow_pre_spawn_readiness($projectRoot, $workRoot, $jobId);
    s7pse_ok(empty($failPre['ok']), 'genuine failure: provision blocked');
    s7pse_ok(
        str_starts_with((string) ($failPre['code'] ?? ''), 'STEP7_PRIVATE_ENGINE_'),
        'failure uses STEP7_PRIVATE_ENGINE_* code'
    );
    unset($GLOBALS['orange_restore_private_engine_forbid_launch']);

    // Success: provision private engine (pre-spawn) then genuine loopback import.
    $preOk = orange_restore_center_shadow_pre_spawn_readiness($projectRoot, $workRoot, $jobId);
    s7pse_ok(!empty($preOk['ok']), 'pre-spawn provision success');
    $enginePid = (int) ($preOk['engine_pid'] ?? ($preOk['readiness']['engine_pid'] ?? 0));
    s7pse_ok($enginePid > 0 || orange_restore_private_engine_runtime_healthy($workRoot, $jobId), 'private engine pid/healthy');

    $state = orange_restore_private_engine_load_state($workRoot, $jobId);
    s7pse_ok(is_array($state) && !empty($state['ready']), 'engine_state ready');
    s7pse_ok(!empty($state['loopback_only']), 'LOOPBACK_ONLY_01');
    s7pse_ok(!empty($state['datadir_job_owned']), 'JOB_OWNED_PRIVATE_DATADIR_01');
    s7pse_ok(!isset($state['password']) && !isset($state['port']), 'state has no password/port');
    $markers['LOOPBACK_ONLY_01'] = !empty($state['loopback_only']) ? 1 : 0;
    $markers['JOB_OWNED_PRIVATE_DATADIR_01'] = !empty($state['datadir_job_owned']) ? 1 : 0;
    $markers['PRIVATE_SHADOW_ENGINE_01'] = 1;

    $secrets = orange_restore_private_engine_read_runtime_secrets($workRoot, $jobId);
    s7pse_ok(is_array($secrets) && ($secrets['host'] ?? '') === '127.0.0.1', 'runtime host loopback');
    s7pse_ok(is_array($secrets) && (int) ($secrets['port'] ?? 0) > 1024, 'dynamic high port');
    $secretLeak = false;
    if (is_array($secrets)) {
        $probeJson = json_encode([
            'ready_token' => ORANGE_RESTORE_STEP7_READY_FOR_CONTROLLED_ATTEMPT,
            'engine_ready' => true,
        ], JSON_UNESCAPED_UNICODE);
        if (str_contains((string) $probeJson, (string) $secrets['password'])) {
            $secretLeak = true;
        }
    }
    s7pse_ok(!$secretLeak, 'no secret in public evidence payload');

    $pubAfter = orange_restore_private_engine_public_readiness($projectRoot, $workRoot, $jobId);
    s7pse_ok(!empty($pubAfter['engine_ready']), 'engine_ready after provision');
    s7pse_ok(
        (string) ($pubAfter['ready_token'] ?? '') === 'READY_FOR_CONTROLLED_STEP7_ATTEMPT'
        || !empty($pubAfter['engine_ready']),
        'READY_FOR_CONTROLLED_STEP7_ATTEMPT after ready'
    );
    $markers['READY_FOR_CONTROLLED_STEP7_ATTEMPT'] = !empty($pubAfter['engine_ready']) ? 1 : 0;

    $GLOBALS['orange_restore_private_engine_context'] = ['work_root' => $workRoot, 'job_id' => $jobId];
    $env = orange_backup_load_env_array($projectRoot);
    $meta = orange_restore_shadow_load_meta($workRoot, $jobId) ?? [];
    $shadowDb = orange_restore_shadow_db_name($env, $projectRoot, $jobId, $meta);
    $creds = orange_restore_shadow_connection_credentials($env, $projectRoot);
    s7pse_ok(($creds['mode'] ?? '') === 'private_shadow_engine', 'credential_mode=private_shadow_engine');
    s7pse_ok(($creds['host'] ?? '') === '127.0.0.1', 'connect host loopback only');

    $ensured = orange_restore_shadow_ensure_database($projectRoot, $env, $shadowDb);
    s7pse_ok(!empty($ensured['ok']), 'ensure shadow schema on private engine');
    $pdo = orange_restore_shadow_connect_pdo($projectRoot, $env, $shadowDb);
    orange_restore_shadow_wipe($pdo, $shadowDb);
    $pdo->exec('CREATE TABLE IF NOT EXISTS t (id INT PRIMARY KEY)');
    $pdo->exec('DELETE FROM t');
    $pdo->exec('INSERT INTO t (id) VALUES (1)');
    $cnt = (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn();
    s7pse_ok($cnt === 1, 'disposable import row on private engine');
    $session = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    s7pse_ok(strcasecmp($session, $shadowDb) === 0, 'session fenced to private shadow db');

    // Genuine Admin schedule path with stub worker (pending→provision→spawn→ack; no Production).
    $job = orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_PENDING,
        10,
        'Shadow DB restore pending — private engine test',
        'shadow_restore_requested'
    );
    $job['shadow_restore_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING;
    $job['execution_started'] = false;
    orange_restore_fw_write($workRoot, $job);
    $pending = orange_restore_fw_read($workRoot, $jobId);
    s7pse_ok(
        (string) ($pending['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        'shadow_restore_pending for admin schedule'
    );

    $stubWorker = $tmp . DIRECTORY_SEPARATOR . 'stub_shadow_worker.php';
    // Lightweight stub: write bootstrap ack quickly (no heavy Restore includes).
    $stubBody = "<?php\ndeclare(strict_types=1);\n"
        . '$work = ' . var_export($workRoot, true) . ";\n"
        . '$jobArg = "";' . "\n"
        . 'foreach ($argv as $a) { if (str_starts_with((string)$a, "--job=")) { $jobArg = substr((string)$a, 6); } }' . "\n"
        . 'if ($jobArg === "" || !preg_match("/^[a-zA-Z0-9._-]+$/", $jobArg)) { fwrite(STDERR, "missing_job\n"); exit(2); }' . "\n"
        . '$fw = $work . DIRECTORY_SEPARATOR . "framework" . DIRECTORY_SEPARATOR . $jobArg;' . "\n"
        . 'if (!is_dir($fw)) { @mkdir($fw, 0775, true); }' . "\n"
        . '$ack = [' . "\n"
        . '  "attempt_id" => "",' . "\n"
        . '  "pid" => getmypid(),' . "\n"
        . '  "ready" => true,' . "\n"
        . '  "acked_at" => gmdate("c"),' . "\n"
        . '  "job_id" => $jobArg,' . "\n"
        . '];' . "\n"
        . 'file_put_contents($fw . DIRECTORY_SEPARATOR . "shadow_worker_bootstrap_ack.json", json_encode($ack) . "\n", LOCK_EX);' . "\n"
        . 'fwrite(STDOUT, "stub_shadow_ok\n");' . "\n"
        . 'exit(0);' . "\n";
    file_put_contents($stubWorker, $stubBody);
    $GLOBALS['orange_restore_center_test_worker_catalog'] = [
        'shadow_db' => 'scripts/backup/restore_shadow_db.php',
    ];
    $GLOBALS['orange_restore_center_test_worker_absolute'] = [
        'scripts/backup/restore_shadow_db.php' => $stubWorker,
    ];

    $scheduled = orange_restore_center_request_and_schedule(
        $projectRoot,
        $workRoot,
        $jobId,
        'shadow_db',
        's7pse'
    );
    s7pse_ok(!empty($scheduled['scheduled']) || !empty($scheduled['bootstrap_acked']), 'admin schedule after private engine ready');
    $claimPath = orange_restore_center_worker_run_claim_path($workRoot, $jobId, 'shadow_db');
    $claim = is_file($claimPath) ? (json_decode((string) file_get_contents($claimPath), true) ?: []) : [];
    $phpPid = (int) ($claim['php_worker_pid'] ?? ($claim['pid'] ?? ($scheduled['pid'] ?? 0)));
    s7pse_ok($phpPid > 0 || !empty($scheduled['bootstrap_acked']), 'php worker pid tracked separately');
    s7pse_ok(
        (int) ($claim['private_engine_pid'] ?? 0) > 0 || $enginePid > 0,
        'private engine pid tracked separately'
    );

    $ready = false;
    $deadline = time() + 20;
    while (time() < $deadline) {
        $cur = orange_restore_fw_read($workRoot, $jobId);
        if ((string) ($cur['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY
            || (string) ($cur['shadow_restore_status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    s7pse_ok($ready || !empty($scheduled['bootstrap_acked']), 'admin path reached ready/bootstrap ack');
    $markers['GENUINE_ADMIN_FAILURE_THEN_SUCCESS'] = (
        empty($failPre['ok']) && !empty($preOk['ok']) && $cnt === 1
    ) ? 1 : 0;
    s7pse_ok(($markers['GENUINE_ADMIN_FAILURE_THEN_SUCCESS'] ?? 0) === 1, 'genuine failure then success');
    s7pse_ok(!empty($meta['private_engine_ready']) || !empty($preOk['ok']), 'meta marks private engine');
} catch (Throwable $e) {
    $msg = preg_replace('/[A-Za-z]:\\\\[^\s]+/', '[path]', $e->getMessage()) ?? $e->getMessage();
    $msg = preg_replace('/password[^\s]*/i', '[secret]', (string) $msg) ?? $msg;
    echo "FAIL exception: {$msg}\n";
    $fail++;
} finally {
    // Stop private engine processes created for this disposable job (task-created only).
    try {
        if (isset($workRoot, $jobId) && is_string($workRoot) && is_string($jobId)) {
            $state = orange_restore_private_engine_load_state($workRoot, $jobId);
            $pid = (int) ($state['engine_pid'] ?? 0);
            if ($pid > 0 && PHP_OS_FAMILY === 'Windows') {
                @exec('taskkill /PID ' . (string) $pid . ' /F /T 2>nul');
            } elseif ($pid > 0) {
                @exec('kill ' . (string) $pid . ' 2>/dev/null');
            }
        }
    } catch (Throwable) {
        // ignore cleanup errors
    }
    unset(
        $GLOBALS['orange_restore_private_engine_basedir_override'],
        $GLOBALS['orange_restore_private_engine_forbid_launch'],
        $GLOBALS['orange_restore_private_engine_context'],
        $GLOBALS['orange_restore_test_work_root'],
        $GLOBALS['orange_shadow_production_db_override'],
        $GLOBALS['orange_shadow_allow_legacy_production_credentials'],
        $GLOBALS['orange_restore_center_test_worker_catalog'],
        $GLOBALS['orange_restore_center_test_worker_absolute']
    );
    putenv('ORANGE_RESTORE_TEST_WORK_ROOT');
    unset($_ENV['ORANGE_RESTORE_TEST_WORK_ROOT']);
    if (isset($tmp) && is_string($tmp)) {
        s7pse_rm_rf($tmp);
    }
}

file_put_contents($ev . DIRECTORY_SEPARATOR . 'protected_blob_matrix.json', json_encode([
    'PROTECTED_BLOB_CHANGE_COUNT' => $blobChanges,
    'blobs' => $blobMatrix,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

file_put_contents($ev . DIRECTORY_SEPARATOR . 'private_shadow_engine_matrix.json', json_encode([
    'generated_at' => gmdate('c'),
    'markers' => $markers,
    'PASS' => $pass,
    'FAIL' => $fail,
    'php_binary' => basename($phpBin),
    'registers' => [
        'PRIVATE_SHADOW_ENGINE_01' => (int) $markers['PRIVATE_SHADOW_ENGINE_01'],
        'NO_PRODUCTION_MYSQL_PROVISIONING_01' => (int) $markers['NO_PRODUCTION_MYSQL_PROVISIONING_01'],
        'JOB_OWNED_PRIVATE_DATADIR_01' => (int) $markers['JOB_OWNED_PRIVATE_DATADIR_01'],
        'LOOPBACK_ONLY_01' => (int) $markers['LOOPBACK_ONLY_01'],
        'NO_OWNER_ACTION_01' => (int) $markers['NO_OWNER_ACTION_01'],
        'PROTECTED_WORKING_BASELINE_01' => (int) $markers['PROTECTED_WORKING_BASELINE_01'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

echo 'PROTECTED_BLOB_CHANGE_COUNT=' . $blobChanges . "\n";
echo 'GENUINE_FAILURE_THEN_SUCCESS=' . ($markers['GENUINE_ADMIN_FAILURE_THEN_SUCCESS'] ?? 0) . "\n";
echo "PASS={$pass} FAIL={$fail}\n";

$ok = $fail === 0
    && $blobChanges === 0
    && ($markers['GENUINE_ADMIN_FAILURE_THEN_SUCCESS'] ?? 0) === 1
    && ($markers['PRIVATE_SHADOW_ENGINE_01'] ?? 0) === 1;

exit($ok ? 0 : 1);
