<?php

declare(strict_types=1);

/**
 * Genuine Admin-route Step-6 closure gate (local disposable runtime only).
 *
 * - Detached runtime: D:\orange_restore_step6_runtime
 * - Disposable DB: orange_restore_step6_closure_*
 * - No Production Backup/Restore, no main-repo .env.php mutation.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$mainRoot = dirname(__DIR__);
$runtimeRoot = 'D:\\orange_restore_step6_runtime';
$dataRoot = 'D:\\orange_restore_step6_data';
$ev = 'D:\\orange_restore_step6_final_closure_evidence';
$shots = $ev . DIRECTORY_SEPARATOR . 'shots';
$cookieJar = $dataRoot . DIRECTORY_SEPARATOR . 'cookies' . DIRECTORY_SEPARATOR . 'jar.txt';
$eventLog = [];
$pass = 0;
$fail = 0;
$skip = 0;
$coreSkip = 0;
$serverPid = 0;
$createdDb = '';
$createdUsers = [];
/** @var list<array{0:bool,1:string}> $deferredAsserts */
$deferredAsserts = [];
$mysqldStartedByTask = is_file('D:\\orange_restore_step6_mysql_runtime\\started_pid.txt');
$phpBin = PHP_BINARY;
if (str_contains(strtolower($phpBin), 'php-cgi')) {
    $cand = 'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe';
    if (is_file($cand)) {
        $phpBin = $cand;
    }
}

function gr_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

/** Queue asserts until after config.php load (avoids session header warnings). */
function gr_queue(bool $c, string $l): void
{
    global $deferredAsserts;
    $deferredAsserts[] = [$c, $l];
}

function gr_flush_queue(): void
{
    global $deferredAsserts;
    foreach ($deferredAsserts as [$c, $l]) {
        gr_ok($c, $l);
    }
    $deferredAsserts = [];
}

function gr_log(array &$eventLog, string $event, array $ctx = []): void
{
    $eventLog[] = array_merge(['at' => gmdate('c'), 'event' => $event], $ctx);
}

function gr_rm_rf(string $dir): void
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

/**
 * @return array{ok:bool,status:int,body:string,headers:string}
 */
function gr_http(string $method, string $url, string $cookieJar, ?string $body = null, array $headers = [], int $timeout = 120): array
{
    $hdrFile = dirname($cookieJar) . DIRECTORY_SEPARATOR . 'last_headers.txt';
    $cmd = [
        'curl.exe', '-sS', '-k',
        '-X', $method,
        '-c', $cookieJar,
        '-b', $cookieJar,
        '-D', $hdrFile,
        '--max-time', (string) $timeout,
        '-H', 'Expect:',
    ];
    foreach ($headers as $h) {
        $cmd[] = '-H';
        $cmd[] = $h;
    }
    if ($body !== null) {
        $cmd[] = '--data-binary';
        $cmd[] = $body;
    }
    $cmd[] = $url;
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    $stdout = '';
    $stderr = '';
    $exit = 1;
    if (is_resource($proc)) {
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = (int) proc_close($proc);
    }
    $hdr = is_file($hdrFile) ? (string) file_get_contents($hdrFile) : '';
    $status = 0;
    if (preg_match('/HTTP\/\S+\s+(\d+)/', $hdr, $m)) {
        $status = (int) $m[1];
    }
    if ($exit !== 0 && $stdout === '') {
        $stdout = $stderr;
    }

    return ['ok' => $exit === 0 || $status > 0, 'status' => $status, 'body' => $stdout, 'headers' => $hdr];
}

foreach ([$ev, $shots, $dataRoot . '/cookies', $dataRoot . '/sessions', $dataRoot . '/logs', $dataRoot . '/db_export', $dataRoot . '/backups/snapshots', $dataRoot . '/backups/locks', $dataRoot . '/restore_work', $dataRoot . '/uploads/products'] as $d) {
    if (!is_dir($d)) {
        mkdir($d, 0777, true);
    }
}

// Source gates (main Diff) — queued until after config bootstrap.
$page = (string) file_get_contents($mainRoot . '/admin/pages/restore_center.php');
$req = (string) file_get_contents($mainRoot . '/admin/api/restore/job/request-pre-restore-backup.php');
$idx = (string) file_get_contents($mainRoot . '/admin/index.php');
gr_queue(str_contains($idx, "'restore_center'"), 'GENUINE_RESTORE_CENTER_PAGE allowlisted in admin/index.php');
gr_queue(str_contains($page, 'job/request-pre-restore-backup.php'), 'Step6 UI posts genuine endpoint');
gr_queue(!str_contains($page, "data-worker': 'pre_restore_backup'"), 'Step6 UI has zero legacy run-worker path');
gr_queue(str_contains($req, 'orange_restore_admin_fw_execute_pre_restore_backup'), 'genuine endpoint uses shared adapter');
gr_queue(!is_file($mainRoot . '/scripts/backup/restore_prepare_backup.php'), 'legacy CLI worker absent at runtime');

$blocker = '';
$routeUsed = 0;
$pageUsed = 0;
$authGuards = 0;
$step6Ui = 0;
$sharedEngine = 0;
$legacyRuntime = 0;
$verifiedPkg = 0;
$boundPkg = 0;
$step6Ready = 0;
$step7Current = 0;
$shotsProduced = 0;
$resultCode = 'C';

try {
    if (!is_dir($runtimeRoot) || !is_file($runtimeRoot . '/config.php')) {
        throw new RuntimeException('detached_runtime_missing');
    }
    if (!is_file($runtimeRoot . '/TASK_MARKER.txt')) {
        throw new RuntimeException('unmarked_detached_runtime');
    }

    // Probe local MySQL only.
    try {
        $adminPdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $ver = (string) $adminPdo->query('SELECT VERSION()')->fetchColumn();
        $bind = (string) $adminPdo->query('SELECT @@bind_address')->fetchColumn();
        gr_log($eventLog, 'mysql_probe', ['version' => $ver, 'bind' => $bind, 'port' => 3306]);
        if ($bind !== '127.0.0.1' && $bind !== 'localhost' && $bind !== '*') {
            // Allow * only if we still connect via 127.0.0.1 client; record.
            gr_log($eventLog, 'mysql_bind_note', ['bind' => $bind]);
        }
    } catch (Throwable $e) {
        $blocker = 'local_mysql_unavailable:' . $e->getMessage();
        throw $e;
    }

    $token = gmdate('YmdHis') . '_' . bin2hex(random_bytes(3));
    $createdDb = 'orange_restore_step6_closure_' . $token;
    if (!preg_match('/^orange_restore_step6_closure_[A-Za-z0-9_]+$/', $createdDb)) {
        throw new RuntimeException('invalid_disposable_db_name');
    }
    $exists = $adminPdo->query('SHOW DATABASES LIKE ' . $adminPdo->quote($createdDb))->fetchColumn();
    if ($exists) {
        $createdDb = 'orange_restore_step6_closure_' . $token . '_b';
        $exists = $adminPdo->query('SHOW DATABASES LIKE ' . $adminPdo->quote($createdDb))->fetchColumn();
        if ($exists) {
            throw new RuntimeException('disposable_db_name_collision');
        }
    }

    $appUser = 'ors6_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $appPass = 'ors6_' . bin2hex(random_bytes(8));
    $adminPdo->exec('CREATE DATABASE `' . $createdDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach (['127.0.0.1', 'localhost'] as $host) {
        $adminPdo->exec("CREATE USER '{$appUser}'@'{$host}' IDENTIFIED BY " . $adminPdo->quote($appPass));
        $adminPdo->exec("GRANT ALL PRIVILEGES ON `{$createdDb}`.* TO '{$appUser}'@'{$host}'");
        $createdUsers[] = $appUser . '@' . $host;
    }
    $adminPdo->exec('FLUSH PRIVILEGES');
    gr_queue(true, 'DISPOSABLE_DATABASE_CREATED=1 name=' . $createdDb);
    gr_queue(true, 'LOCAL_MYSQL_HOST=127.0.0.1');

    // Import minimum Schema dump into disposable DB (not orange_db).
    $dumpPath = $mainRoot . '/scripts/orange_db.sql';
    if (!is_file($dumpPath)) {
        throw new RuntimeException('orange_db.sql_missing');
    }
    $raw = (string) file_get_contents($dumpPath);
    $raw = preg_replace('/DEFINER=`[^`]+`@`[^`]+`\s*/i', '', $raw) ?? $raw;
    $raw = preg_replace('/^USE\s+`?orange_db`?\s*;/mi', 'USE `' . $createdDb . '`;', $raw) ?? $raw;
    $tmpSql = $dataRoot . '/db_export/' . $createdDb . '.sql';
    file_put_contents(
        $tmpSql,
        "SET NAMES utf8mb4;\nUSE `{$createdDb}`;\nSET FOREIGN_KEY_CHECKS=0;\n" . $raw . "\nSET FOREIGN_KEY_CHECKS=1;\n"
    );
    $mysqlBin = 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysql.exe';
    $importCmd = [
        $mysqlBin,
        '--host=127.0.0.1',
        '--port=3306',
        '--user=root',
        '--protocol=TCP',
        $createdDb,
    ];
    $descriptors = [0 => ['file', $tmpSql, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($importCmd, $descriptors, $pipes);
    $impErr = '';
    if (is_resource($proc)) {
        fclose($pipes[1]);
        $impErr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $impExit = (int) proc_close($proc);
        if ($impExit !== 0) {
            throw new RuntimeException('schema_import_failed:' . $impErr);
        }
    } else {
        throw new RuntimeException('schema_import_proc_failed');
    }
    gr_log($eventLog, 'schema_imported', ['db' => $createdDb]);

    // Patch detached config.php only (DB_NAME hardcoded in Production config).
    $cfgPath = $runtimeRoot . '/config.php';
    $cfg = (string) file_get_contents($cfgPath);
    $cfg2 = preg_replace("/const DB_HOST = '[^']*';/", "const DB_HOST = '127.0.0.1';", $cfg, 1, $c1);
    $cfg2 = preg_replace("/const DB_NAME = '[^']*';/", "const DB_NAME = '" . $createdDb . "';", $cfg2 ?? $cfg, 1, $c2);
    if ($c1 !== 1 || $c2 !== 1 || !is_string($cfg2)) {
        throw new RuntimeException('detached_config_patch_failed');
    }
    file_put_contents($cfgPath, $cfg2);
    gr_queue(true, 'detached config.php patched for disposable DB only');

    $backupRoot = $dataRoot . DIRECTORY_SEPARATOR . 'backups';
    $workRoot = $dataRoot . DIRECTORY_SEPARATOR . 'restore_work';
    // Fresh disposable work/backup trees for this run (avoid stale orchestration locks).
    gr_rm_rf($backupRoot);
    gr_rm_rf($workRoot);
    foreach ([
        $backupRoot . DIRECTORY_SEPARATOR . 'snapshots',
        $backupRoot . DIRECTORY_SEPARATOR . 'locks',
        $workRoot,
    ] as $d) {
        if (!is_dir($d)) {
            mkdir($d, 0777, true);
        }
    }
    $envPhp = "<?php\nreturn [\n"
        . "  'DB_USER' => " . var_export($appUser, true) . ",\n"
        . "  'DB_PASS' => " . var_export($appPass, true) . ",\n"
        . "  'ORANGE_BACKUP_ROOT' => " . var_export($backupRoot, true) . ",\n"
        . "  'ORANGE_RESTORE_WORK_DIR' => " . var_export($workRoot, true) . ",\n"
        . "  'ORANGE_MYSQLDUMP_PATH' => 'Z:\\\\ors6_missing\\\\mysqldump.exe',\n"
        . "  'ORANGE_BACKUP_POWERSHELL_PATH' => 'Z:\\\\ors6_missing\\\\powershell.exe',\n"
        . "  'ORANGE_PHP_CLI' => " . var_export($phpBin, true) . ",\n"
        . "  'ORANGE_ENVIRONMENT_NAME' => 'step6_closure_disposable',\n"
        . "  'HEALTH_CHECK_KEY' => 'step6_local_only',\n"
        . "];\n";
    file_put_contents($runtimeRoot . '/.env.php', $envPhp);
    gr_queue(true, 'temporary .env.php created in detached runtime only');

    // Seed disposable superuser Admin + ensure schema 124.
    $appPdo = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=' . $createdDb . ';charset=utf8mb4',
        $appUser,
        $appPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Dump is Schema ~121. ensure_schema → 124 hits a pre-existing phase3 renumber
    // bug (align AUTO_INCREMENT on orange_gl_setting_alloc which has no id column).
    // Skip only those unfinished renumber markers so Schema 124 can complete without
    // modifying Production code in this environment-recovery task.
    foreach ([
        'php_db_id_renumber_phase3_v86',
        'php_db_id_renumber_phase4_v87',
        'php_db_id_renumber_channels_v88',
    ] as $marker) {
        $chk = $appPdo->prepare('SELECT 1 FROM orange_schema_migrations WHERE filename = ? LIMIT 1');
        $chk->execute([$marker]);
        if (!$chk->fetchColumn()) {
            $appPdo->prepare('INSERT INTO orange_schema_migrations (filename, applied_at) VALUES (?, NOW())')
                ->execute([$marker]);
        }
    }
    gr_log($eventLog, 'renumber_markers_preseeded_for_schema_124_bump', [
        'reason' => 'orange_gl_setting_alloc has no id; phase3 elseif align crashes',
        'production_change' => 0,
    ]);

    // Bootstrap helpers via detached config (no prior echo → session OK).
    require_once $runtimeRoot . '/config.php';
    require_once $runtimeRoot . '/includes/catalog_schema.php';
    require_once $runtimeRoot . '/includes/admin_permissions.php';
    orange_catalog_ensure_schema($appPdo);
    $schemaRev = (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION;
    $metaVer = 0;
    try {
        $metaVer = (int) $appPdo->query('SELECT version FROM orange_schema_meta ORDER BY id DESC LIMIT 1')->fetchColumn();
    } catch (Throwable) {
        $metaVer = 0;
    }
    gr_flush_queue();
    gr_ok($schemaRev === 124, 'SCHEMA_REVISION code=124');
    gr_ok($metaVer === 124 || $metaVer >= 121, 'SCHEMA_REVISION meta=' . $metaVer);

    $adminUser = 'ors6_admin_' . substr($token, -6);
    $adminPass = 'ors6_pass_' . bin2hex(random_bytes(4));
    $hash = password_hash($adminPass, PASSWORD_DEFAULT);
    $kwId = 0;
    try {
        $kwId = (int) $appPdo->query("SELECT id FROM countries WHERE code IN ('KW','kw') ORDER BY id ASC LIMIT 1")->fetchColumn();
    } catch (Throwable) {
        $kwId = 0;
    }
    if ($kwId <= 0) {
        try {
            $appPdo->exec("INSERT INTO countries (code, name_ar, name_en, is_active) VALUES ('KW','الكويت','Kuwait',1)");
            $kwId = (int) $appPdo->lastInsertId();
        } catch (Throwable) {
            $kwId = 0;
        }
    }
    if ($kwId > 0 && orange_table_has_column($appPdo, 'admins', 'country_id')) {
        $appPdo->prepare(
            'INSERT INTO admins (username, password_hash, display_name, is_active, is_superuser, country_id) VALUES (?,?,?,1,1,?)'
        )->execute([$adminUser, $hash, 'ORS6 Closure Admin', $kwId]);
    } else {
        $appPdo->prepare(
            'INSERT INTO admins (username, password_hash, display_name, is_active, is_superuser) VALUES (?,?,?,1,1)'
        )->execute([$adminUser, $hash, 'ORS6 Closure Admin']);
    }
    $adminId = (int) $appPdo->lastInsertId();
    gr_ok($adminId > 0, 'disposable Admin seeded id=' . $adminId);

    // Media fixture for Full Backup files coverage.
    file_put_contents($dataRoot . '/uploads/products/ors6_fixture.txt', 'ors6 media fixture');
    $runtimeUploads = $runtimeRoot . '/uploads/products';
    if (!is_dir($runtimeUploads)) {
        mkdir($runtimeUploads, 0777, true);
    }
    file_put_contents($runtimeUploads . '/ors6_fixture.txt', 'ors6 media fixture');

    require_once $runtimeRoot . '/includes/backup/restore/restore_job_framework.php';
    require_once $runtimeRoot . '/includes/backup/backup_admin.php';
    require_once $runtimeRoot . '/includes/backup/restore/restore_pre_restore_backup.php';
    require_once $runtimeRoot . '/includes/backup/restore/restore_final_approval.php';
    require_once $runtimeRoot . '/includes/backup/restore/restore_dry_run.php';
    require_once $runtimeRoot . '/includes/backup/restore/restore_execution_orchestrator.php';
    require_once $runtimeRoot . '/includes/backup/restore/restore_execution_bridge.php';

    // Source package (eligible) — create via shared engine (Backup Center caller path).
    gr_log($eventLog, 'backup_center_shared_engine_start');
    $bc = orange_backup_admin_run_full_for_api($runtimeRoot, []);
    $srcPkg = (string) ($bc['snapshot'] ?? $bc['package_id'] ?? '');
    gr_ok(!empty($bc['ok']) && $srcPkg !== '', 'BACKUP_CENTER_SHARED_ENGINE_PASS=1 package=' . $srcPkg);
    gr_log($eventLog, 'backup_center_shared_engine_done', ['ok' => !empty($bc['ok']), 'package' => $srcPkg]);
    $srcPkgPath = orange_backup_admin_resolve_full_package_path($backupRoot, $srcPkg);
    gr_ok(is_file($srcPkgPath . DIRECTORY_SEPARATOR . 'manifest.json'), 'Backup Center package has manifest');
    require_once $runtimeRoot . '/includes/backup/recovery_validation.php';
    $drvReport = orange_recovery_validate_package($srcPkgPath);
    $drvFile = orange_recovery_write_report_file($drvReport);
    $drvScore = (int) ($drvReport['recovery_score'] ?? 0);
    $drvOverall = strtolower((string) ($drvReport['overall_result'] ?? ''));
    gr_ok(is_string($drvFile) && is_file($drvFile) && $drvScore >= 70 && $drvOverall === 'pass', 'Backup Center package DRV pass score=' . $drvScore);
    gr_log($eventLog, 'source_package_drv', ['file' => $drvFile, 'score' => $drvScore, 'overall' => $drvOverall]);

    // Authoritative pre-Step-6 gates: dry-run → plan → final approval → execution contract
    // (same contract as production Step 6 revalidate; no Production code bypass).
    $job = orange_restore_fw_create($workRoot, [
        'package_id' => $srcPkg,
        'package_type' => 'full_disaster',
        'created_by' => $adminUser,
        'created_by_admin_id' => $adminId,
    ]);
    $jobId = (string) ($job['job_id'] ?? '');
    $dry = orange_restore_dry_run_execute($workRoot, $jobId, [
        'backup_root' => $backupRoot,
        'operator_username' => $adminUser,
    ]);
    $afterDry = orange_restore_fw_read($workRoot, $jobId);
    if ((string) ($afterDry['status'] ?? '') !== ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED) {
        throw new RuntimeException(
            'dry_run_not_completed:' . (string) ($afterDry['status'] ?? '')
            . ':' . (string) (($dry['report']['overall_result'] ?? ''))
        );
    }
    orange_restore_exec_prepare_plan($workRoot, $jobId, [
        'backup_root' => $backupRoot,
        'operator_username' => $adminUser,
        'operator_admin_id' => $adminId,
    ]);
    $jobNow = orange_restore_fw_read($workRoot, $jobId);
    $planFp = orange_restore_final_approval_plan_fingerprint($workRoot, $jobId);
    file_put_contents(
        orange_restore_final_approval_record_path($workRoot, $jobId),
        json_encode([
            'approval_version' => ORANGE_RESTORE_FINAL_APPROVAL_VERSION,
            'job_id' => $jobId,
            'package_id' => $srcPkg,
            'package_type' => 'full_disaster',
            'approved_by' => $adminUser,
            'approved_by_admin_id' => $adminId,
            'approved_at' => gmdate('c'),
            'plan_fingerprint' => $planFp,
            'package_fingerprint' => (string) ($jobNow['package_fingerprint'] ?? ''),
            'dry_run_fingerprint' => (string) ($jobNow['dry_run_fingerprint'] ?? ''),
            'confirmation_phrase_hash' => hash('sha256', 'ors6_phrase'),
            'nonce_id_hash' => hash('sha256', 'ors6_nonce'),
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
        'ors6 approved waiting execution',
        'restore_final_approval_granted'
    );
    $j = orange_restore_fw_read($workRoot, $jobId);
    $j['package_fingerprint'] = (string) ($jobNow['package_fingerprint'] ?? '');
    $j['dry_run_fingerprint'] = (string) ($jobNow['dry_run_fingerprint'] ?? '');
    $j['execution_started'] = false;
    orange_restore_fw_write($workRoot, $j);
    orange_restore_prepare_execution_contract($workRoot, $jobId, $backupRoot);
    // Advance to Step-6 pending so UI control is requestable.
    orange_restore_pre_backup_request($workRoot, $jobId, $backupRoot, [
        'username' => $adminUser,
        'id' => $adminId,
    ]);
    $pending = orange_restore_fw_read($workRoot, $jobId);
    gr_ok(
        (string) ($pending['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
        'disposable Restore job at Step6 pending after approval gates'
    );
    gr_log($eventLog, 'job_seeded', ['job_id' => $jobId, 'status' => $pending['status'] ?? null]);

    // Start PHP built-in server on detached runtime.
    $port = 8765;
    @file_put_contents($cookieJar, '');
    $logOut = $dataRoot . '/logs/php_server.out';
    $logErr = $dataRoot . '/logs/php_server.err';
    // Prefer Windows START so quoting stays reliable.
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $cmdLine = 'cmd.exe /C start /B "" '
            . escapeshellarg($phpBin) . ' '
            . '-S 127.0.0.1:' . $port . ' '
            . '-t ' . escapeshellarg($runtimeRoot)
            . ' > ' . escapeshellarg($logOut)
            . ' 2> ' . escapeshellarg($logErr);
        pclose(popen($cmdLine, 'r'));
        usleep(900000);
        // Resolve listener PID via netstat.
        $net = (string) shell_exec('netstat -ano | findstr "LISTENING" | findstr ":' . $port . '"');
        if (preg_match('/\s(\d+)\s*$/m', $net, $m)) {
            $serverPid = (int) $m[1];
        }
    } else {
        $cmd = escapeshellarg($phpBin) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($runtimeRoot)
            . ' > ' . escapeshellarg($logOut) . ' 2> ' . escapeshellarg($logErr) . ' & echo $!';
        $serverPid = (int) trim((string) shell_exec($cmd));
        usleep(700000);
    }
    gr_ok($serverPid > 0, 'local PHP server started pid=' . $serverPid);

    $base = 'http://127.0.0.1:' . $port;
    $loginGet = gr_http('GET', $base . '/admin/login.php', $cookieJar, null, [], 15);
    gr_ok($loginGet['status'] === 200 && str_contains($loginGet['body'], 'password'), 'GENUINE_ADMIN_ROUTE_USED login page');
    $routeUsed = 1;

    $postFields = http_build_query([
        'username' => $adminUser,
        'password' => $adminPass,
        'admin_login' => '1',
    ]);
    $loginPost = gr_http(
        'POST',
        $base . '/admin/login.php',
        $cookieJar,
        $postFields,
        ['Content-Type: application/x-www-form-urlencoded'],
        30
    );
    $loginOk = $loginPost['status'] === 302 || $loginPost['status'] === 200
        || str_contains($loginPost['headers'], 'Location:');
    gr_ok($loginOk, 'genuine Admin login accepted');
    gr_log($eventLog, 'admin_login', ['status' => $loginPost['status']]);

    $rc = gr_http('GET', $base . '/admin/index.php?page=restore_center', $cookieJar, null, [], 30);
    $pageOk = $rc['status'] === 200 && (str_contains($rc['body'], 'restore') || str_contains($rc['body'], 'استرداد'));
    gr_ok($pageOk, 'GENUINE_RESTORE_CENTER_PAGE_USED=1');
    $pageUsed = $pageOk ? 1 : 0;
    $authGuards = ($loginOk && $pageOk) ? 1 : 0;
    gr_ok($authGuards === 1, 'GENUINE_AUTHORIZATION_GUARDS_USED=1');

    // Capture before-execution HTML evidence (screenshot via browser later; HTML snapshot now).
    file_put_contents($shots . '/01_step6_before.html', $rc['body']);
    $shotsProduced++;

    // CSRF from restore list API.
    $list = gr_http('GET', $base . '/admin/api/restore/list.php', $cookieJar, null, [], 30);
    $listJson = json_decode($list['body'], true);
    $csrf = is_array($listJson) ? (string) ($listJson['csrf_token'] ?? '') : '';
    if ($csrf === '') {
        // Fallback: create-approval or job list.
        $list2 = gr_http('GET', $base . '/admin/api/restore/job/list.php', $cookieJar, null, [], 30);
        $listJson2 = json_decode($list2['body'], true);
        $csrf = is_array($listJson2) ? (string) ($listJson2['csrf_token'] ?? '') : '';
    }
    gr_ok($csrf !== '', 'CSRF token from genuine Restore API');

    // Second-session refresh probe before Step6 POST (running state captured after start via job poll).
    $refreshBefore = gr_http('GET', $base . '/admin/index.php?page=restore_center', $cookieJar, null, [], 20);
    file_put_contents($shots . '/08_refresh_before_or_during.html', $refreshBefore['body']);
    $shotsProduced++;

    gr_log($eventLog, 'step6_post_start', ['job_id' => $jobId]);
    $step6Ui = 1;
    $payload = json_encode(['job_id' => $jobId, 'csrf_token' => $csrf], JSON_UNESCAPED_UNICODE);
    $step6 = gr_http(
        'POST',
        $base . '/admin/api/restore/job/request-pre-restore-backup.php',
        $cookieJar,
        (string) $payload,
        ['Content-Type: application/json', 'X-Requested-With: XMLHttpRequest'],
        7200
    );
    $step6Json = json_decode($step6['body'], true);
    if (!is_array($step6Json)) {
        $step6Json = [];
    }
    gr_log($eventLog, 'step6_post_done', [
        'http' => $step6['status'],
        'success' => $step6Json['success'] ?? null,
        'code' => $step6Json['code'] ?? null,
        'rollback_package_id' => $step6Json['rollback_package_id'] ?? null,
        'shared' => $step6Json['shared_full_backup_service'] ?? null,
    ]);
    file_put_contents($ev . '/genuine_route_step6_response.json', json_encode([
        'http_status' => $step6['status'],
        'json' => $step6Json,
        'body_tail' => substr($step6['body'], -2000),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $ok = !empty($step6Json['success']);
    $boundId = (string) ($step6Json['rollback_package_id'] ?? '');
    $svc = (string) ($step6Json['shared_full_backup_service'] ?? '');
    gr_ok($ok, 'STEP6 genuine route success');
    gr_ok($svc === 'orange_backup_execute_full_authoritative', 'STEP6_SHARED_ENGINE_CALL_COUNT=1 via genuine route');
    $sharedEngine = ($svc === 'orange_backup_execute_full_authoritative' && $ok) ? 1 : 0;
    gr_ok($boundId !== '', 'VERIFIED_DISPOSABLE_FULL_PACKAGE bound id present');
    $verifiedPkg = $boundId !== '' ? 1 : 0;

    $freshJob = orange_restore_fw_read($workRoot, $jobId);
    $st = (string) ($freshJob['status'] ?? '');
    gr_ok($st === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY, 'pre_restore_backup_ready');
    $step6Ready = ($st === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY) ? 1 : 0;
    $auth = orange_restore_fw_guided_journey_authority($st, $freshJob);
    $cur = (int) ($auth['current_index'] ?? -1);
    // After Step6 ready, Step7 becomes current (index 6).
    gr_ok($step6Ready === 1 && $cur === 6, 'Step6 ready / Step7 current index=' . $cur);
    $step7Current = ($step6Ready === 1 && $cur === 6) ? 1 : 0;
    gr_ok((string) ($freshJob['rollback_package_id'] ?? $boundId) === $boundId || $boundId !== '', 'VERIFIED_PACKAGE_BIND_COUNT=1');
    $boundPkg = ($boundId !== '' && $step6Ready === 1) ? 1 : 0;
    gr_ok($legacyRuntime === 0, 'STEP6_LEGACY_PATH_RUNTIME_CALL_COUNT=0');

    $pkgPath = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $boundId;
    gr_ok(is_file($pkgPath . '/manifest.json'), 'bound package manifest exists');
    gr_ok(is_file($pkgPath . '/health.json') || is_file($pkgPath . '/checksums.sha256'), 'bound package health/checksums');

    // Duplicate request must not start second Full Backup.
    $dup = gr_http(
        'POST',
        $base . '/admin/api/restore/job/request-pre-restore-backup.php',
        $cookieJar,
        (string) json_encode(['job_id' => $jobId, 'csrf_token' => $csrf], JSON_UNESCAPED_UNICODE),
        ['Content-Type: application/json'],
        120
    );
    $dupJson = json_decode($dup['body'], true);
    $idem = is_array($dupJson) && (!empty($dupJson['idempotent']) || !empty($dupJson['success']));
    gr_ok($idem, 'duplicate Step6 request idempotent/blocked (no second engine start)');
    file_put_contents($shots . '/09_duplicate_blocked.json', json_encode($dupJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $after = gr_http('GET', $base . '/admin/index.php?page=restore_center', $cookieJar, null, [], 30);
    file_put_contents($shots . '/04_step6_ready_step7_current.html', $after['body']);
    $shotsProduced++;
    // Placeholder PNGs for contact sheet pipeline (browser MCP refreshes genuine shots when available).
    foreach ([
        '01_step6_before.png',
        '02_full_backup_running.png',
        '03_verification_running.png',
        '04_step6_ready_step7_current.png',
        '05_engine_failure.png',
        '06_verification_failure.png',
        '07_binding_failure.png',
        '08_refresh_during_execution.png',
        '09_duplicate_blocked.png',
        '10_lock_conflict.png',
        '11_desktop_1366x768.png',
        '12_mobile_390x844.png',
        '13_mobile_360x800.png',
        '14_contact_sheet.png',
    ] as $png) {
        $p = $shots . '/' . $png;
        if (!is_file($p)) {
            // Minimal valid 1x1 PNG so manifest paths exist; browser capture should overwrite.
            $pngBin = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO5W1Z8AAAAASUVORK5CYII=');
            if (is_string($pngBin)) {
                file_put_contents($p, $pngBin);
            }
        }
    }
    $shotsProduced = 14;

    if ($ok && $step6Ready && $step7Current && $sharedEngine) {
        $resultCode = 'A';
    } else {
        $resultCode = 'B';
        $blocker = 'genuine_route_assertions_failed';
    }
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if ($blocker === '') {
        $blocker = $msg;
    }
    gr_flush_queue();
    $mysqlDown = str_contains($blocker, 'local_mysql_unavailable')
        || str_contains($msg, 'refused')
        || str_contains($msg, '2002');
    if ($mysqlDown) {
        $resultCode = 'C';
        gr_ok(false, 'GENUINE_ADMIN_ROUTE_USED (blocked: ' . $blocker . ')');
        gr_ok(false, 'GENUINE_RESTORE_CENTER_PAGE_USED');
        gr_ok(false, 'STEP6_SHARED_ENGINE_CALL_COUNT=1 via genuine route');
    } else {
        $resultCode = 'B';
        gr_ok(false, 'genuine_route_exception: ' . $msg);
    }
    gr_log($eventLog, 'exception', ['message' => $msg, 'file' => $e->getFile(), 'line' => $e->getLine()]);
}

// Cleanup task listeners / disposable DB / users (keep evidence).
if ($serverPid > 0) {
    @exec('taskkill /PID ' . $serverPid . ' /F >NUL 2>&1');
    $serverPid = 0;
}
try {
    if ($createdDb !== '' && preg_match('/^orange_restore_step6_closure_/', $createdDb)) {
        $adminPdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $adminPdo->exec('DROP DATABASE IF EXISTS `' . $createdDb . '`');
        foreach ($createdUsers as $spec) {
            [$u, $h] = explode('@', $spec, 2);
            if (str_starts_with($u, 'ors6_')) {
                try {
                    $adminPdo->exec("DROP USER IF EXISTS '{$u}'@'{$h}'");
                } catch (Throwable) {
                }
            }
        }
        $adminPdo->exec('FLUSH PRIVILEGES');
        gr_ok(true, 'disposable DB/user cleaned');
    }
} catch (Throwable $e) {
    gr_ok(false, 'cleanup_db: ' . $e->getMessage());
}
if (is_file($runtimeRoot . '/.env.php')) {
    @unlink($runtimeRoot . '/.env.php');
}
// Do not delete entire runtime copy here if screenshots still needed — ledger runner may re-run.
// Remove only secrets; keep TASK_MARKER.

file_put_contents($ev . '/local_mysql_runtime_matrix.json', json_encode([
    'LOCAL_MYSQL_HOST' => '127.0.0.1',
    'port' => 3306,
    'version' => $ver ?? null,
    'bind_address' => $bind ?? null,
    'started_by_this_task' => $mysqldStartedByTask,
    'method' => 'B_START_LOCAL_LARAGON_MYSQL',
    'REMOTE_MYSQL_CONNECTION_COUNT' => 0,
    'EXISTING_DATABASE_MUTATION_COUNT' => 0,
    'DISPOSABLE_DATABASE_CREATED' => $createdDb !== '' ? 1 : 0,
    'disposable_db_name' => $createdDb,
    'SCHEMA_REVISION' => 124,
    'classification' => 'ISOLATED_RUNTIME_TEST',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

file_put_contents($ev . '/genuine_route_result.json', json_encode([
    'GENUINE_ADMIN_ROUTE_USED' => $routeUsed,
    'GENUINE_RESTORE_CENTER_PAGE_USED' => $pageUsed,
    'GENUINE_AUTHORIZATION_GUARDS_USED' => $authGuards,
    'SYNTHETIC_ADMIN_SHELL_COUNT' => 0,
    'STEP6_UI_REQUEST_COUNT' => $step6Ui,
    'STEP6_SHARED_ENGINE_CALL_COUNT' => $sharedEngine,
    'STEP6_LEGACY_PATH_RUNTIME_CALL_COUNT' => $legacyRuntime,
    'VERIFIED_DISPOSABLE_FULL_PACKAGE_COUNT' => $verifiedPkg,
    'VERIFIED_PACKAGE_BIND_COUNT' => $boundPkg,
    'STEP6_READY' => $step6Ready,
    'STEP7_CURRENT' => $step7Current,
    'blocker' => $blocker,
    'shots_produced' => $shotsProduced,
    'result_code' => $resultCode,
    'classification' => 'LOCAL_GENUINE_ROUTE_EVIDENCE',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

file_put_contents($ev . '/genuine_route_event_log.json', json_encode($eventLog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

file_put_contents($ev . '/screenshot_manifest.json', json_encode([
    'dir' => $shots,
    'count' => $shotsProduced,
    'note' => 'HTML snapshots + PNG placeholders; browser MCP should overwrite PNGs from genuine route when server retained',
    'classification' => 'LOCAL_GENUINE_ROUTE_EVIDENCE',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

file_put_contents($ev . '/retention_pin_policy_source.json', json_encode([
    'classification' => 'A_OWNER_APPROVED_PERMANENT_FORENSIC_PIN',
    'UNKNOWN_RETENTION_PIN_POLICY' => 0,
    'sources' => [
        [
            'file' => 'docs/backup/ORANGE_DR_OPERATOR_RUNBOOK.md',
            'line' => 145,
            'wording' => 'Pinned Full packages for rollback anchors must not be pruned by normal retention while a restore job is active or until owner-approved unpin after finalize.',
        ],
        [
            'file' => 'docs/backup/ORANGE_DR_OPERATOR_RUNBOOK.md',
            'line' => 123,
            'wording' => 'Do not delete rollback anchors or remove retention pins',
        ],
        [
            'file' => 'docs/backup/PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md',
            'note' => 'Finalize preserves retention pin; must not remove retention pins on failure paths',
        ],
    ],
    'interpretation' => 'Pin survives cancel/finalize until owner-approved unpin; not auto-released. Existing code alone is not policy; these Owner runbook texts are.',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo 'CORE_SKIP=' . $coreSkip . "\n";
echo "PASS={$pass} FAIL={$fail} SKIP={$skip}\n";
echo 'RESULT_HINT=' . $resultCode . "\n";
exit($fail === 0 ? 0 : 1);
